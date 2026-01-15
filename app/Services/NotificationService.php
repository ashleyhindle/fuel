<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Service for sending notifications via sounds and desktop notifications.
 * Supports macOS sounds via afplay and terminal notifications via OSC escape sequences.
 */
class NotificationService
{
    /** Default sound file for macOS */
    private const DEFAULT_SOUND = '/System/Library/Sounds/Purr.aiff';

    /** Default volume (0.0 - 1.0) */
    private const DEFAULT_VOLUME = 0.6;

    public function __construct(
        private readonly ConfigService $configService
    ) {}

    /**
     * Play a notification sound (non-blocking).
     * Currently supports macOS only, Linux support planned.
     *
     * @param  string|null  $sound  Path to sound file (macOS default: Purr.aiff)
     * @param  float  $volume  Volume level 0.0-1.0 (default: 0.6)
     */
    public function playSound(?string $sound = null, float $volume = self::DEFAULT_VOLUME): void
    {
        $sound ??= self::DEFAULT_SOUND;
        $volume = max(0.0, min(1.0, $volume));

        if ($this->isMacOS()) {
            $this->playMacOSSound($sound, $volume);
        } elseif ($this->isLinux()) {
            $this->playLinuxSound($sound, $volume);
        }

        // Windows/other: silently skip for now
    }

    /**
     * Send a desktop notification via OSC escape sequence.
     * Works in terminals that support OSC 9 (iTerm2, Windows Terminal, etc.)
     * or OSC 777 (rxvt-unicode, some others).
     *
     * @param  string  $message  The notification message
     * @param  string|null  $title  Optional title (not all terminals support this)
     */
    public function notify(string $message, ?string $title = null): void
    {
        // OSC 9 - Simple notification (iTerm2, Windows Terminal)
        // Format: ESC ] 9 ; message BEL
        $this->writeEscape("\033]9;{$message}\007");

        // OSC 777 - Notification with title (rxvt-unicode style)
        // Format: ESC ] 777 ; notify ; title ; message BEL
        if ($title !== null) {
            $this->writeEscape("\033]777;notify;{$title};{$message}\007");
        }
    }

    /**
     * Send both a sound and desktop notification.
     *
     * @param  string  $message  The notification message
     * @param  string|null  $title  Optional title
     * @param  string|null  $sound  Path to sound file
     * @param  float  $volume  Volume level 0.0-1.0
     */
    public function alert(string $message, ?string $title = null, ?string $sound = null, float $volume = self::DEFAULT_VOLUME): void
    {
        $this->playSound($sound, $volume);
        $this->notify($message, $title);
        $this->desktopNotify($message, $title);
    }

    /**
     * Send a native desktop notification.
     * Uses terminal-notifier on macOS, notify-send on Linux.
     * Works in headless/daemon scenarios where OSC escape sequences don't work.
     *
     * @param  string  $message  The notification message
     * @param  string|null  $title  Optional title (defaults to 'Fuel')
     */
    public function desktopNotify(string $message, ?string $title = null): void
    {
        if (! $this->configService->getDesktopNotifications()) {
            return;
        }

        $title ??= 'Fuel';

        if ($this->isMacOS()) {
            $this->sendMacOSNotification($message, $title);
        } elseif ($this->isLinux()) {
            $this->sendLinuxNotification($message, $title);
        }
    }

    /**
     * Send notification on macOS using terminal-notifier or osascript.
     */
    private function sendMacOSNotification(string $message, string $title): void
    {
        // Try terminal-notifier first (better experience, needs to be installed)
        if ($this->commandExists('terminal-notifier')) {
            exec(sprintf(
                'terminal-notifier -title %s -message %s -group fuel > /dev/null 2>&1 &',
                escapeshellarg($title),
                escapeshellarg($message)
            ));

            return;
        }

        // Fall back to osascript (built-in, less features)
        exec(sprintf(
            'osascript -e %s > /dev/null 2>&1 &',
            escapeshellarg(sprintf('display notification "%s" with title "%s"', addslashes($message), addslashes($title)))
        ));
    }

    /**
     * Send notification on Linux using notify-send.
     */
    private function sendLinuxNotification(string $message, string $title): void
    {
        if (! $this->commandExists('notify-send')) {
            return;
        }

        exec(sprintf(
            'notify-send %s %s > /dev/null 2>&1 &',
            escapeshellarg($title),
            escapeshellarg($message)
        ));
    }

    /**
     * Play sound on macOS using afplay (backgrounded).
     */
    private function playMacOSSound(string $sound, float $volume): void
    {
        if (! file_exists($sound)) {
            return;
        }

        // Background the process so we don't block
        exec(sprintf(
            'afplay -v %s %s > /dev/null 2>&1 &',
            escapeshellarg((string) $volume),
            escapeshellarg($sound)
        ));
    }

    /**
     * Play sound on Linux using paplay or aplay (backgrounded).
     */
    private function playLinuxSound(string $sound, float $volume): void
    {
        if (! file_exists($sound)) {
            return;
        }

        // Try paplay (PulseAudio) first, fall back to aplay (ALSA)
        $volumePercent = (int) ($volume * 100);

        if ($this->commandExists('paplay')) {
            exec(sprintf(
                'paplay --volume=%d %s > /dev/null 2>&1 &',
                (int) ($volumePercent * 655.36), // paplay uses 0-65536
                escapeshellarg($sound)
            ));
        } elseif ($this->commandExists('aplay')) {
            // aplay doesn't have volume control, just play
            exec(sprintf(
                'aplay -q %s > /dev/null 2>&1 &',
                escapeshellarg($sound)
            ));
        }
    }

    /**
     * Write an escape sequence to STDOUT.
     */
    private function writeEscape(string $sequence): void
    {
        // Only write if we have a TTY
        if (function_exists('posix_isatty') && ! posix_isatty(STDOUT)) {
            return;
        }

        echo $sequence;
    }

    /**
     * Check if running on macOS.
     */
    private function isMacOS(): bool
    {
        return PHP_OS === 'Darwin';
    }

    /**
     * Check if running on Linux.
     */
    private function isLinux(): bool
    {
        return PHP_OS === 'Linux';
    }

    /**
     * Check if a command exists in PATH.
     */
    private function commandExists(string $command): bool
    {
        $result = shell_exec(sprintf('which %s 2>/dev/null', escapeshellarg($command)));

        return $result !== null && trim($result) !== '';
    }
}
