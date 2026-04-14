<?php
class ZabbixApi
{
    private $url;
    private $auth = null;
    private $timeout = 30;
    private $verifySsl = false;
    private $extraHeaders = [];
    private $username = null;
    private $password = null;

    public function __construct($url, $user, $pass, $opt = [])
    {
        $this->url = (string)$url;
        $this->username = (string)$user;
        $this->password = (string)$pass;

        if (is_array($opt)) {
            $this->timeout = isset($opt['timeout']) ? (int)$opt['timeout'] : 30;
            $this->verifySsl = isset($opt['verify_ssl']) ? (bool)$opt['verify_ssl'] : false;
            $this->extraHeaders = (isset($opt['headers']) && is_array($opt['headers'])) ? $opt['headers'] : [];
        } elseif ($opt !== null) {
            $this->timeout = (int)$opt;
        }
    }

    private function rpc(array $payload)
    {
        $ch = curl_init($this->url);
        $headers = array_merge(
            ['Content-Type: application/json-rpc; charset=UTF-8'],
            $this->extraHeaders
        );

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ]);

        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException('[API HTTP] ' . $err);
        }
        $json = json_decode($resp, true);
        if (!is_array($json)) {
            throw new RuntimeException('Respuesta API inválida');
        }
        if (isset($json['error'])) {
            $e = $json['error'];
            $msg = 'API error: ' . json_encode($e, JSON_UNESCAPED_UNICODE);
            throw new RuntimeException($msg);
        }
        return $json['result'] ?? null;
    }

    public function call($method, $params = [])
    {
        $payload = [
            'jsonrpc' => '2.0',
            'method' => (string)$method,
            'params' => $params,
            'id' => 1,
        ];
        if ($this->auth) {
            $payload['auth'] = $this->auth;
        }
        return $this->rpc($payload);
    }

    public function login($user, $pass)
    {
        $res = $this->rpc([
            'jsonrpc' => '2.0',
            'method' => 'user.login',
            'params' => ['user' => (string)$user, 'password' => (string)$pass],
            'id' => 1
        ]);
        if (!is_string($res) || $res === '') {
            throw new RuntimeException('Login API falló: token vacío');
        }
        $this->auth = $res;
        return true;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function hostMapByNames(array $names)
    {
        if (empty($names)) return [];
        $result = $this->call('host.get', [
            'output' => ['hostid','host','name'],
            'filter' => ['host' => $names],
        ]);

        $map = [];
        if (is_array($result)) {
            foreach ($result as $h) {
                $key = $h['host'] ?? $h['name'] ?? '';
                if ($key !== '' && isset($h['hostid'])) $map[$key] = $h['hostid'];
            }
        }
        if (!empty($map)) return $map;

        $result2 = $this->call('host.get', [
            'output' => ['hostid','host','name'],
            'search' => ['name' => $names],
            'searchWildcardsEnabled' => true,
        ]);
        if (is_array($result2)) {
            foreach ($result2 as $h) {
                if (isset($h['hostid'])) {
                    $key = $h['host'] ?? $h['name'] ?? '';
                    if ($key !== '') $map[$key] = $h['hostid'];
                }
            }
        }
        return $map;
    }

    public function hostIdsByGroupIds(array $groupids)
    {
        if (empty($groupids)) return [];
        $res = $this->call('host.get', [
            'output' => ['hostid'],
            'groupids' => $groupids,
        ]);
        $ids = [];
        if (is_array($res)) {
            foreach ($res as $h) {
                if (isset($h['hostid'])) $ids[] = $h['hostid'];
            }
        }
        return array_values(array_unique($ids));
    }

    public function hostGetBasicByIds(array $hostids)
    {
        if (empty($hostids)) return [];
        $res = $this->call('host.get', [
            'output' => ['hostid','host','name'],
            'hostids' => $hostids,
            'sortfield' => 'host',
        ]);
        return is_array($res) ? $res : [];
    }

    public function itemGetByHostAndKeys($hostid, array $keys)
    {
        if (!$hostid || empty($keys)) return [];
        $res = $this->call('item.get', [
            'output' => ['itemid','name','key_'],
            'hostids' => [$hostid],
            'filter' => ['key_' => $keys]
        ]);
        return is_array($res) ? $res : [];
    }

    public function graphGetByHostIds(array $hostids)
    {
        if (empty($hostids)) return [];
        $res = $this->call('graph.get', [
            'output' => ['graphid','name'],
            'hostids' => $hostids,
            'sortfield' => 'name',
        ]);
        return is_array($res) ? $res : [];
    }
}