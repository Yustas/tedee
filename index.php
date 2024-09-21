<?php

error_reporting(E_ALL);
ini_set("display_errors", 1);

require __DIR__ . '/vendor/autoload.php';

use Prometheus\Storage\Adapter;
use Prometheus\Storage\Redis;
use Prometheus\CollectorRegistry;
use Prometheus\Exception\MetricsRegistrationException;

class Tedee
{
    const UNKNOWN = 'unknown';

    const METRIC_NAMESPACE = 'tedee';

    const DEVICE_TYPE = [
        2 => 'Lock Pro',
        4 => 'Lock Go',
    ];

    const STATUS_CONNECTED = [
        0 => 'disconnected',
        1 => 'connected',
    ];

    const STATUS_LOCK = [
        0 => 'uncalibrated',
        1 => 'calibration',
        2 => 'open',
        3 => 'partially_open',
        4 => 'opening',
        5 => 'closing',
        6 => 'closed',
        7 => 'pull_spring',
        8 => 'pulling',
        9 => 'unknown',
        255 => 'unpulling',
    ];

    const STATUS_JAMMED = [
        0 => 'not jammed',
        1 => 'jammed',
    ];

    private array $request;
    private CollectorRegistry $registry;

    function __construct(
        private Adapter $storage
    )
    {
        $this->registry = new CollectorRegistry($this->storage);
    }

    public function register(array $request): void
    {
        $this->request = $request;

        try {
            if (!$this->isValid()) {
                throw new MetricsRegistrationException("Invalid request");
            }

            $this->metrics();
        } catch (MetricsRegistrationException $e) {
            user_error('Metrics registration error: ' . $e->getMessage(), E_USER_WARNING);
        }
    }

    public function debug(): void
    {
        file_put_contents('webhook', json_encode($this->request) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function getEvent(): string
    {
        return $this->request['event'];
    }

    /**
     * @throws MetricsRegistrationException
     */
    private function metrics(): void
    {
        match ($this->getEvent()) {
            'backend-connection-changed' => $this->backendConnectionChanged(),
            'device-connection-changed' => $this->deviceConnectionChanged(),
            'device-settings-changed' => $this->deviceSettingsChanged(),
            'lock-status-changed' => $this->lockStatusChanged(),
            'device-battery-level-changed' => $this->deviceBatteryLevelChanged(),
            'device-battery-start-charging',
            'device-battery-stop-charging' => $this->deviceBatteryCharging(),
            'device-battery-fully-charged' => $this->deviceBatteryCharged(),
            default => $this->unknownEvent(),
        };
    }

    /**
     * @throws MetricsRegistrationException
     */
    private function backendConnectionChanged(): void
    {
        $this->registry
            ->getOrRegisterCounter(
                self::METRIC_NAMESPACE,
                'backend_connection_changed_total',
                'it observes change of backend connection', ['isConnected'])
            ->inc([
                self::STATUS_CONNECTED[$this->request['isConnected']] ?? self::UNKNOWN,
            ]);
    }

    /**
     * @throws MetricsRegistrationException
     */
    private function deviceConnectionChanged(): void
    {
        $this->registry
            ->getOrRegisterCounter(
                self::METRIC_NAMESPACE,
                'device_connection_changed_total',
                'it observes change of device connection to the bridge', ['deviceType', 'isConnected'])
            ->inc([
                self::DEVICE_TYPE[$this->request['deviceType']] ?? self::UNKNOWN,
                self::STATUS_CONNECTED[$this->request['isConnected']] ?? self::UNKNOWN,
            ]);
    }

    /**
     * @throws MetricsRegistrationException
     */
    private function deviceSettingsChanged(): void
    {
        $this->registry
            ->getOrRegisterCounter(
                self::METRIC_NAMESPACE,
                'device_settings_changed_total',
                'it observes change of device settings', ['deviceType'])
            ->inc([
                self::DEVICE_TYPE[$this->request['deviceType']] ?? self::UNKNOWN,
            ]);
    }

    /**
     * @throws MetricsRegistrationException
     */
    private function lockStatusChanged(): void
    {
        $this->registry
            ->getOrRegisterCounter(
                self::METRIC_NAMESPACE,
                'lock_status_changed_total',
                'it observes lock status change', ['state', 'deviceType', 'jammed'])
            ->inc([
                self::STATUS_LOCK[(int)($this->request['state'])] ?? self::UNKNOWN,
                self::DEVICE_TYPE[$this->request['deviceType']] ?? self::UNKNOWN,
                self::STATUS_JAMMED[$this->request['jammed']] ?? self::UNKNOWN,
            ]);
    }

    /**
     * @throws MetricsRegistrationException
     */
    private function deviceBatteryLevelChanged(): void
    {
        $this->registry
            ->getOrRegisterGauge(
                self::METRIC_NAMESPACE,
                'device_battery_level_ratio',
                'it observes lock battery level change', ['deviceType']
            )
            ->set(
                $this->request['batteryLevel'],
                [
                    self::DEVICE_TYPE[$this->request['deviceType']] ?? self::UNKNOWN,
                ]
            );
    }

    /**
     * @throws MetricsRegistrationException
     */
    private function deviceBatteryCharging(): void
    {
        $this->registry
            ->getOrRegisterHistogram(
                self::METRIC_NAMESPACE,
                'device_battery_charging_duration_seconds',
                'it observes lock charging status', ['deviceType'])
            ->observe(time(), [self::DEVICE_TYPE[$this->request['deviceType']] ?? self::UNKNOWN]);
    }

    /**
     * @throws MetricsRegistrationException
     */
    private function deviceBatteryCharged(): void
    {
        $this->registry
            ->getOrRegisterCounter(
                self::METRIC_NAMESPACE,
                'device_battery_fully_charged_total',
                'it observes fully charge state', ['deviceType'])
            ->inc([
                self::DEVICE_TYPE[$this->request['deviceType']] ?? self::UNKNOWN,
            ]);
    }

    private function unknownEvent(): void
    {
        user_error('Unknown event: ' . json_encode($this->request), E_USER_WARNING);
    }

    private function isValid(): bool
    {
        return !empty($this->request) && isset($this->request['event']);
    }
}

Redis::setDefaultOptions(
    [
        'host' => '127.0.0.1',
        'port' => 6379,
        'password' => null,
        'timeout' => 0.1, // in seconds
        'read_timeout' => '10', // in seconds
        'persistent_connections' => false
    ]
);

$tedee = new Tedee(new Redis());
$tedee->register($_REQUEST);
$tedee->debug();
