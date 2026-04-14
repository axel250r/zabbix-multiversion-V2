<?php
require_once __DIR__ . '/ZabbixApiAdapter.php';

class ZabbixApi70Adapter extends ZabbixApiAdapter
{
    private $api;
    private $token;
    
    public function __construct($url, $user, $pass, $options = null)
    {
        // Crear instancia SIN login automático
        $this->api = new ZabbixApi($url, $user, $pass, $options);
        
        // Hacer login manual con el formato correcto
        $this->login();
    }
    
    /**
     * Login con formato Zabbix 7.x (username)
     */
    private function login(): void
    {
        $payload = [
            'jsonrpc' => '2.0',
            'method' => 'user.login',
            'params' => [
                'username' => $this->api->getUsername(),
                'password' => $this->api->getPassword()
            ],
            'id' => 1
        ];
        
        // Usar reflexión para acceder al método privado rpc
        $reflection = new ReflectionClass($this->api);
        $method = $reflection->getMethod('rpc');
        $method->setAccessible(true);
        
        // rpc() devuelve SOLO el token
        $this->token = $method->invoke($this->api, $payload);
    }
    
    /**
     * Intercepta todas las llamadas y las adapta a Zabbix 7.x
     */
    public function call(string $method, array $params = [])
    {
        // Construir payload sin 'auth' en el body
        $payload = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => 1
        ];
        
        // Usar reflexión para acceder al método privado rpc
        $reflection = new ReflectionClass($this->api);
        $method_rpc = $reflection->getMethod('rpc');
        $method_rpc->setAccessible(true);
        
        // Necesitamos modificar temporalmente los headers para incluir el token
        $property = $reflection->getProperty('extraHeaders');
        $property->setAccessible(true);
        
        // Guardar headers originales
        $originalHeaders = $property->getValue($this->api);
        
        // Añadir header Authorization con el token
        $newHeaders = $originalHeaders;
        $newHeaders[] = 'Authorization: Bearer ' . $this->token;
        $property->setValue($this->api, $newHeaders);
        
        try {
            // Llamar a la API con los headers modificados
            $result = $method_rpc->invoke($this->api, $payload);
            
            // Restaurar headers originales
            $property->setValue($this->api, $originalHeaders);
            
            return $result;
        } catch (Throwable $e) {
            // Restaurar headers originales incluso si hay error
            $property->setValue($this->api, $originalHeaders);
            throw $e;
        }
    }
    
    public function getUserType(string $username): int
    {
        $user_info = $this->call('user.get', [
            'output' => ['userid', 'username'],
            'selectRole' => ['type'],
            'filter' => ['username' => $username]
        ]);
        return (int)($user_info[0]['role']['type'] ?? 1);
    }
    
    public function createMaintenance(array $params, array $hostids): array
    {
        $hosts_array = [];
        foreach ($hostids as $hid) {
            $hosts_array[] = ['hostid' => $hid];
        }
        $params['hosts'] = $hosts_array;
        return $this->call('maintenance.create', $params) ?? [];
    }
    
    public function updateMaintenance(string $maintenanceid, array $hostids): array
    {
        $hosts_array = [];
        foreach ($hostids as $hid) {
            $hosts_array[] = ['hostid' => $hid];
        }
        return $this->call('maintenance.update', [
            'maintenanceid' => $maintenanceid,
            'hosts' => $hosts_array
        ]) ?? [];
    }
    
    public function getHostsByNames(array $names): array
    {
        if (empty($names)) return [];
        
        $hosts = $this->call('host.get', [
            'output' => ['hostid', 'host', 'name'],
            'filter' => ['host' => $names]
        ]);
        
        $map = [];
        if (is_array($hosts)) {
            foreach ($hosts as $h) {
                $key = $h['host'] ?? $h['name'] ?? '';
                if ($key && isset($h['hostid'])) {
                    $map[$key] = $h['hostid'];
                }
            }
        }
        return $map;
    }
    
    public function getHostIdsByGroupIds(array $groupids): array
    {
        if (empty($groupids)) return [];
        
        $hosts = $this->call('host.get', [
            'output' => ['hostid'],
            'groupids' => $groupids
        ]);
        
        return is_array($hosts) ? array_column($hosts, 'hostid') : [];
    }
}