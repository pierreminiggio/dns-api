<?php

namespace App;

use PierreMiniggio\ConfigProvider\ConfigProvider;

class App
{

    public function __construct(private ConfigProvider $configProvider)
    {
    }

    public function run(
        string $path,
        ?string $queryParameters,
        string $method,
        ?string $body,
        ?string $authHeader
    ): void
    {
        $config = $this->configProvider->get();
        
        if (! $authHeader || $authHeader !== 'Bearer ' . $config['apiToken']) {
            http_response_code(401);
            
            return;
        }

        if ($method !== 'PUT') {
            http_response_code(404);
            
            return;
        }

        if ($path !== '/') {
            http_response_code(404);
            
            return;
        }

        if (! $body) {
            http_response_code(400);
            
            return;
        }

        $jsonBody = json_decode($body, true);

        if (! $jsonBody) {
            http_response_code(400);
            
            return;
        }

        if (! isset($jsonBody['domain'])) {
            http_response_code(400);
            
            return;
        }

        if (! isset($jsonBody['ip'])) {
            http_response_code(400);
            
            return;
        }

        $domain = $jsonBody['domain'];
        $ip = $jsonBody['ip'];

        $domainFile = $config['domainFile'];
        $fileHandle = fopen($domainFile, 'r+');
        $previousLineIsSOA = false;
        $domainInList = false;
        $currentDomainLineStart = $domain . ' IN A ';

        $newLines = [];

        while (! feof($fileHandle)) {
            $line = fgets($fileHandle);

            $newLine = $line;

            if ($previousLineIsSOA) {
                $newLine = array_reduce(
                    explode(' ', $line),
                    function (string | null $accumulator, string $item): string {
                        if ($accumulator !== null) {
                            $accumulator .= ' ';
                        }
                        if (is_numeric($item)) {
                            $item += 1;
                        }

                        return $accumulator . $item;
                    }
                );
            }

            $currentLineIsTheDomain = str_starts_with($line, $currentDomainLineStart);
            
            if ($currentLineIsTheDomain) {
                $domainInList = true;
                $currentIp = trim(substr($line, strlen($currentDomainLineStart)));

                if ($currentIp === $ip) {
                    http_response_code(201);

                    return;
                }
                
                $newLine = $currentDomainLineStart . $ip . PHP_EOL;
            }

            $previousLineIsSOA = str_starts_with($line, '@ IN SOA');
            $newLines[] = $newLine;
        }

        fclose($fileHandle);

        if (! $domainInList) {
            http_response_code(400);

            return;
        }

        file_put_contents($domainFile, implode($newLines));
        shell_exec($config['restartDNSCommand']);
        http_response_code(201);
    }
}
