<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Infrastructure\Verification;

use EindSupplierSearch\Infrastructure\Verification\VerificationSource;
use PHPUnit\Framework\TestCase;

final class VerificationSourceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/gate-source-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/modules/eindsuppliersearch', 0700, true);
        mkdir($this->directory . '/app/config', 0700, true);
        file_put_contents($this->directory . '/modules/eindsuppliersearch/eindsuppliersearch.php', "<?php // \$this->version = '9.9.9';\n\$this->version = '1.1.0';\n\$this->name = 'eindsuppliersearch';");
        file_put_contents($this->directory . '/modules/eindsuppliersearch/config.xml', '<module><name>eindsuppliersearch</name><version>1.1.0</version></module>');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($this->directory);
    }

    public function testSourceIsParsedWithoutExecutingModuleAndCommentsAreIgnored(): void
    {
        self::assertSame(['version' => '1.1.0', 'agrees' => true], VerificationSource::read($this->directory . '/modules/eindsuppliersearch'));
        file_put_contents($this->directory . '/modules/eindsuppliersearch/config.xml', '<module><name>eindsuppliersearch</name><version>9.9.9</version></module>');
        self::assertFalse(VerificationSource::read($this->directory . '/modules/eindsuppliersearch')['agrees']);
    }

    public function testConfigurationMatchesLiteralNativeParametersWithoutExecutingThem(): void
    {
        $config = ['host' => 'isolated-db', 'port' => 3306, 'database' => 'isolated', 'prefix' => 'life_',
            'user' => 'reader', 'password' => 'not-a-real-secret', 'server_hostname' => 'isolated-db', 'metadata_definer' => 'metadata@localhost'];
        file_put_contents($this->directory . '/config.json', json_encode($config));
        file_put_contents($this->directory . '/app/config/parameters.php', "<?php throw new RuntimeException('MUST NOT EXECUTE');\nreturn ['database_host' => 'isolated-db', 'database_name' => 'isolated', 'database_prefix' => 'life_', 'database_port' => ''];");
        self::assertSame($config, VerificationSource::configuration($this->directory . '/config.json', $this->directory . '/modules/eindsuppliersearch'));
        $config['database'] = 'other';
        file_put_contents($this->directory . '/config.json', json_encode($config));
        $this->expectException(\RuntimeException::class);
        VerificationSource::configuration($this->directory . '/config.json', $this->directory . '/modules/eindsuppliersearch');
    }

    public function testExternalXmlEntitiesAreRejected(): void
    {
        file_put_contents($this->directory . '/modules/eindsuppliersearch/config.xml', '<!DOCTYPE module SYSTEM "file:///secret"><module/>');
        $this->expectException(\RuntimeException::class);
        VerificationSource::read($this->directory . '/modules/eindsuppliersearch');
    }
}
