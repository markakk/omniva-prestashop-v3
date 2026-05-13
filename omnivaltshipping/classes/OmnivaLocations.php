<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class OmnivaLocations
{
    const SOURCE_URL = 'https://omniva.ee/locationsfull.json';
    const FILENAME = 'locations.json';

    private string $lastError = '';

    public static function getFilePath(): string
    {
        return dirname(__DIR__) . '/' . self::FILENAME;
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    public function update(): bool
    {
        $this->lastError = '';

        $pickups = @file_get_contents(self::SOURCE_URL);
        if ($pickups === false) {
            $this->lastError = 'Failed to download locations from ' . self::SOURCE_URL;
            return false;
        }

        $terminals = json_decode($pickups, true);
        if (!is_array($terminals)) {
            $this->lastError = 'Failed to decode locations JSON';
            return false;
        }

        $filtered = array_filter($terminals, fn($item) =>
            (float) $item['X_COORDINATE'] > 0 && (float) $item['Y_COORDINATE'] > 0
        );

        $json = json_encode(array_values($filtered));
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->lastError = 'Failed to encode filtered locations: ' . json_last_error_msg();
            return false;
        }

        $filePath = self::getFilePath();
        $dir = dirname($filePath);
        if (!is_writable($dir)) {
            $this->lastError = 'Directory is not writable: ' . $dir;
            return false;
        }

        $tmpFile = $filePath . '.tmp.' . getmypid();
        $result = @file_put_contents($tmpFile, $json, LOCK_EX);
        if ($result === false) {
            @unlink($tmpFile);
            $this->lastError = 'Failed to write file: ' . $filePath;
            return false;
        }

        if (!@rename($tmpFile, $filePath)) {
            @unlink($tmpFile);
            $this->lastError = 'Failed to replace file: ' . $filePath;
            return false;
        }

        Configuration::updateValue('omnivalt_locations_update', time());
        return true;
    }

    public function updateIfNeeded(): bool
    {
        $last_update = (int) Configuration::get('omnivalt_locations_update');
        $file = self::getFilePath();

        if (!$last_update || ($last_update + 86400) < time() || !file_exists($file)) {
            return $this->update();
        }

        return true;
    }

    public static function getAll(): array
    {
        $file = self::getFilePath();
        if (!file_exists($file)) {
            return [];
        }

        $terminals = json_decode(file_get_contents($file), true);
        return is_array($terminals) ? $terminals : [];
    }

    public static function getFiltered(string $country, ?int $type = null): array
    {
        $all = self::getAll();
        if (empty($all)) {
            return [];
        }

        return array_values(array_filter($all, function ($terminal) use ($country, $type) {
            if (strtoupper($terminal['A0_NAME']) !== strtoupper($country)) {
                return false;
            }
            if ($type !== null && (int) $terminal['TYPE'] !== $type) {
                return false;
            }
            return true;
        }));
    }

    public static function getTerminalAddress(string $code): string
    {
        $all = self::getAll();

        foreach ($all as $terminal) {
            if ($terminal['ZIP'] == $code) {
                return $terminal['NAME'] . ', ' . $terminal['A2_NAME'] . ', ' . $terminal['A0_NAME'];
            }
        }

        return '';
    }

    public static function getLastUpdateFormatted(): string
    {
        $last_update = Configuration::get('omnivalt_locations_update');
        return $last_update ? date('Y-m-d H:i:s', (int) $last_update) : '--';
    }
}
