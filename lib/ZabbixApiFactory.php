<?php
require_once __DIR__ . '/ZabbixApi.php';
require_once __DIR__ . '/adapters/ZabbixApi60Adapter.php';
require_once __DIR__ . '/adapters/ZabbixApi64Adapter.php';
require_once __DIR__ . '/adapters/ZabbixApi70Adapter.php';

class ZabbixApiFactory
{
    private static $versionCache = [];
    
    public static function create(string $url, string $user, string $pass, $options = null): ZabbixApiAdapter
    {
        $version = self::detectVersion($url);
        error_log("ZabbixApiFactory: Versión detectada: $version");
        
        if (version_compare($version, '7.0', '>=')) {
            return new ZabbixApi70Adapter($url, $user, $pass, $options);
        } elseif (version_compare($version, '6.4', '>=')) {
            return new ZabbixApi64Adapter($url, $user, $pass, $options);
        } else {
            return new ZabbixApi60Adapter($url, $user, $pass, $options);
        }
    }
    
    private static function detectVersion(string $url): string
    {
        $cacheKey = md5($url);
        if (isset(self::$versionCache[$cacheKey])) {
            return self::$versionCache[$cacheKey];
        }
        
        if (isset($_SESSION['zabbix_version'])) {
            self::$versionCache[$cacheKey] = $_SESSION['zabbix_version'];
            return $_SESSION['zabbix_version'];
        }
        
        // ============================================================
        // MÉTODO 1: Intentar apiinfo.version SIN NINGUNA AUTENTICACIÓN
        // ============================================================
        $version = self::tryApiInfoVersion($url);
        if ($version !== null) {
            $_SESSION['zabbix_version'] = $version;
            self::$versionCache[$cacheKey] = $version;
            return $version;
        }
        
        // ============================================================
        // MÉTODO 2: Probar por comportamiento (sin usar apiinfo.version)
        // ============================================================
        $version = self::detectByBehavior($url);
        if ($version !== null) {
            $_SESSION['zabbix_version'] = $version;
            self::$versionCache[$cacheKey] = $version;
            return $version;
        }
        
        // ============================================================
        // FALLBACK FINAL: Asumir 6.0 (el más compatible)
        // ============================================================
        return '6.0.0';
    }
    
    private static function tryApiInfoVersion(string $url): ?string
    {
        try {
            $ch = curl_init(rtrim($url, '/') . '/api_jsonrpc.php');
            $payload = json_encode([
                'jsonrpc' => '2.0',
                'method' => 'apiinfo.version',
                'params' => [],
                'id' => 1
            ]);
            
            // Configurar cURL SIN ABSOLUTAMENTE NADA de autenticación
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json-rpc'],
                CURLOPT_TIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT => 'ZabbixApiFactory/1.0',
                // FORZAR a no enviar autenticación
                CURLOPT_HTTPAUTH => CURLAUTH_ANY,
                CURLOPT_USERPWD => '',
                CURLOPT_UNRESTRICTED_AUTH => false,
            ]);
            
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 && $resp) {
                $data = json_decode($resp, true);
                if (isset($data['result'])) {
                    return $data['result'];
                }
            }
        } catch (Throwable $e) {
            error_log("ZabbixApiFactory: Error en apiinfo.version: " . $e->getMessage());
        }
        
        return null;
    }
    
    private static function detectByBehavior(string $url): ?string
    {
        // Probar primero con login en formato 7.0 (username)
        try {
            $testPayload = json_encode([
                'jsonrpc' => '2.0',
                'method' => 'user.login',
                'params' => [
                    'username' => 'test',
                    'password' => 'test'
                ],
                'id' => 1
            ]);
            
            $ch = curl_init(rtrim($url, '/') . '/api_jsonrpc.php');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $testPayload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json-rpc'],
                CURLOPT_TIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            
            curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);
            
            // Si el error es por credenciales (no por sintaxis), entonces es 7.0+
            if (strpos($error, 'error') === false) {
                // La sintaxis es válida, aunque las credenciales sean incorrectas
                return '7.0.0';
            }
        } catch (Throwable $e) {
            // Ignorar
        }
        
        // Si llegamos aquí, probablemente es 6.0
        return '6.0.0';
    }
}