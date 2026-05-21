<?php
declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Pontifex\Cli\DoctorCommand;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Smoke test for the DoctorCommand class.
 *
 * v0.0.1 has no WordPress runtime available in unit context, so this
 * test asserts only the structural invariants of the class: it exists,
 * is final, exposes __invoke, and the invoke signature is the void
 * return we expect WP-CLI to receive.
 *
 * Behavioural assertions (what each check actually returns) will arrive
 * in v0.0.2 when we add brain/monkey for mocked WordPress functions.
 * Until then, this test exists primarily to prove the testing pipeline
 * itself works — that PHPUnit runs, the autoloader resolves classes,
 * pre-commit and pre-push see a passing suite, and CI is green.
 */
final class DoctorCommandTest extends TestCase
{
    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists(DoctorCommand::class));
    }

    public function test_class_is_final(): void
    {
        $reflection = new ReflectionClass(DoctorCommand::class);
        $this->assertTrue(
            $reflection->isFinal(),
            'DoctorCommand is marked final to prevent extension; loosening this requires deliberate review.'
        );
    }

    public function test_invoke_method_exists(): void
    {
        $this->assertTrue(
            method_exists(DoctorCommand::class, '__invoke'),
            'WP-CLI single-command classes must expose __invoke.'
        );
    }

    public function test_invoke_returns_void(): void
    {
        $invoke_reflection = new ReflectionMethod(DoctorCommand::class, '__invoke');
        $return_type       = $invoke_reflection->getReturnType();

        $this->assertInstanceOf(
            ReflectionNamedType::class,
            $return_type,
            '__invoke must declare an explicit return type.'
        );
        $this->assertSame(
            'void',
            $return_type->getName(),
            'WP-CLI single-command __invoke must return void.'
        );
    }
}
