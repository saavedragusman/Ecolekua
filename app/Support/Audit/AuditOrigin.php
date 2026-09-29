<?php

namespace App\Support\Audit;

use Illuminate\Http\Request;

/**
 * Where an audited event came from: a web request (client IP) or a console command
 * (no IP; source, command, OS user and host recorded in the context). See design Decision 9.
 */
final readonly class AuditOrigin
{
    /**
     * @param  array<string, string>  $context
     */
    private function __construct(
        private ?string $ipAddress,
        private array $context,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self($request->ip(), []);
    }

    public static function console(string $command): self
    {
        return new self(null, [
            'source' => 'console',
            'command' => $command,
            'os_user' => self::osUser(),
            'host' => (string) gethostname(),
        ]);
    }

    public function ipAddress(): ?string
    {
        return $this->ipAddress;
    }

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * Effective OS user of the process, best effort.
     */
    private static function osUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());

            if (is_array($info) && $info['name'] !== '') {
                return $info['name'];
            }
        }

        $user = get_current_user();

        return $user !== '' ? $user : 'unknown';
    }
}
