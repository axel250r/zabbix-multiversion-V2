<?php
require_once __DIR__ . '/ZabbixApiAdapter.php';

class ZabbixApi64Adapter extends ZabbixApiAdapter
{
    private $api;
    
    public function __construct($url, $user, $pass, $options = null)
    {
        $this->api = new ZabbixApi($url, $user, $pass, $options);
        $this->login();
    }
    
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
        
        $reflection = new ReflectionClass($this->api);
        $method = $reflection->getMethod('rpc');
        $method->setAccessible(true);
        
        $token = $method->invoke($this->api, $payload);
        
        $property = $reflection->getProperty('auth');
        $property->setAccessible(true);
        $property->setValue($this->api, $token);
    }
    
    public function call(string $method, array $params = [])
    {
        return $this->api->call($method, $params);
    }
    
    public function getUserType(string $username): int
    {
        $user_info = $this->call('user.get', [
            'output' => ['type'],
            'filter' => ['username' => $username]
        ]);
        return (int)($user_info[0]['type'] ?? 1);
    }
    
    public function createMaintenance(array $params, array $hostids): array
    {
        $params['hostids'] = $hostids;
        return $this->call('maintenance.create', $params) ?? [];
    }
    
    public function updateMaintenance(string $maintenanceid, array $hostids): array
    {
        return $this->call('maintenance.update', [
            'maintenanceid' => $maintenanceid,
            'hostids' => $hostids
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