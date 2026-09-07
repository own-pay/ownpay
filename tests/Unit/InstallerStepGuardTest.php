<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Controller\Install\InstallerController;
use OwnPay\Http\Request;
use PHPUnit\Framework\TestCase;

final class InstallerStepGuardTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = sys_get_temp_dir() . '/op_installer_step_guard_' . bin2hex(random_bytes(6));
        mkdir($this->tempRoot . '/storage', 0777, true);

        // Presence of .env.temp allows step 3+, but DB probe will still fail
        // because this host is intentionally unreachable in test.
        file_put_contents(
            $this->tempRoot . '/storage/.env.temp',
            "DB_HOST=invalid-host.local\nDB_PORT=3306\nDB_NAME=ownpay\nDB_USER=root\nDB_PASS=\nDB_PREFIX=op_\n"
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->tempRoot . '/storage/.installed');
        @unlink($this->tempRoot . '/storage/.env.temp');
        @rmdir($this->tempRoot . '/storage');
        @rmdir($this->tempRoot);

        parent::tearDown();
    }

    public function testStepFourRedirectsBackToStepThreeWhenAdminStepIncomplete(): void
    {
        $controller = $this->makeController();

        $response = $controller->show(new Request(
            ['step' => '4'],
            [],
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/install?step=4']
        ));

        self::assertSame(302, $response->getStatusCode());
        $headers = $response->getHeaders();
        self::assertSame('/install?step=3', $headers['Location'] ?? '');
    }

    public function testFinalizeRequiresAdminCreationFirst(): void
    {
        $controller = $this->makeController();

        $response = $controller->finalize(new Request(
            [],
            [],
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/install/finalize'],
            [],
            [],
            '{}'
        ));

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('Complete admin step first', $body['error'] ?? null);
    }

    private function makeController(): InstallerController
    {
        $controller = new InstallerController();

        $rootProp = new \ReflectionProperty(InstallerController::class, 'rootDir');
        $rootProp->setValue($controller, $this->tempRoot);

        $markerProp = new \ReflectionProperty(InstallerController::class, 'markerFile');
        $markerProp->setValue($controller, $this->tempRoot . '/storage/.installed');

        $probeProp = new \ReflectionProperty(InstallerController::class, 'dbProbeResult');
        $probeProp->setValue($controller, false);

        return $controller;
    }
}
