<?php
abstract class ZabbixApiAdapter
{
    abstract public function call(string $method, array $params = []);
    abstract public function getUserType(string $username): int;
    abstract public function createMaintenance(array $params, array $hostids): array;
    abstract public function updateMaintenance(string $maintenanceid, array $hostids): array;
    abstract public function getHostsByNames(array $names): array;
    abstract public function getHostIdsByGroupIds(array $groupids): array;
}