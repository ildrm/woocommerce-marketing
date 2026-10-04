<?php
/** Only the stable WP-CLI public surface used by the plugin; no runtime implementation. */
class WP_CLI {
    public static function line(string $message): void {}
    public static function success(string $message): void {}
    public static function add_command(string $name,object $command): bool { return true; }
}
