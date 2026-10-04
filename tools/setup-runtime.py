"""Provision only the isolated .runtime WordPress used by integration tests."""
import base64
import os
from pathlib import Path
import secrets
import subprocess

ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT / ".runtime"
WORDPRESS = Path('/private/tmp/wmos-wordpress')


def wp(*args: str, input_text: str | None = None) -> None:
    environment = dict(os.environ, WP_CLI_CACHE_DIR="/private/tmp/wmos-wp-cache")
    result = subprocess.run(
        ["php", "-d", "memory_limit=512M", "-d", "max_execution_time=120", "-d", "error_reporting=24575", "/opt/homebrew/bin/wp", f"--path={WORDPRESS}", *args],
        input=input_text, text=True, capture_output=True, env=environment,
    )
    if result.returncode:
        raise RuntimeError("Isolated WordPress setup command failed; inspect its local configuration.")
    print("WordPress test setup: " + " ".join(args[:2]) + " succeeded.")


def main() -> None:
    RUNTIME.mkdir(exist_ok=True)
    WORDPRESS.mkdir(exist_ok=True)
    link=RUNTIME / 'wordpress'
    if not link.is_symlink():
        if link.exists():
            raise RuntimeError('The isolated runtime must live outside the plugin checkout.')
        link.symlink_to(WORDPRESS,target_is_directory=True)
    if not (WORDPRESS / 'wp-load.php').exists():
        wp('core','download','--version=7.1.2','--force')
    mysql = dict(line.split("=", 1) for line in (RUNTIME / "mysql.env").read_text().splitlines())
    if not (WORDPRESS / "wp-config.php").exists():
        wp("config", "create", "--dbname=wmos_test", "--dbuser=root", "--dbhost=127.0.0.1:13306", "--prompt=dbpass", input_text=mysql["MYSQL_ROOT_PASSWORD"] + "\n")
        configuration = WORDPRESS / "wp-config.php"
        contents = configuration.read_text()
        additions = (
            "define('WMOS_ENCRYPTION_KEY', '" + base64.b64encode(secrets.token_bytes(32)).decode() + "');\n"
            "define('WP_DEBUG_LOG', true);\ndefine('WP_DEBUG_DISPLAY', false);\n"
            "define('DISABLE_WP_CRON', true);\n"
        )
        contents = contents.replace("define( 'WP_DEBUG', false );", "define( 'WP_DEBUG', true );")
        contents = contents.replace("$table_prefix = 'wp_';", "$table_prefix = getenv('WMOS_TEST_PREFIX') ?: 'wp_';")
        contents = contents.replace("define( 'DB_HOST', '127.0.0.1:13306' );", "define( 'DB_HOST', getenv('WMOS_TEST_DBHOST') ?: '127.0.0.1:13306' );")
        contents = contents.replace("/* That's all, stop editing! Happy publishing. */", additions + "\n/* That's all, stop editing! Happy publishing. */")
        configuration.write_text(contents)
        configuration.chmod(0o600)
    installed = subprocess.run(["php", "-d", "memory_limit=512M", "-d", "error_reporting=24575", "/opt/homebrew/bin/wp", f"--path={WORDPRESS}", "core", "is-installed"], capture_output=True).returncode == 0
    if not installed:
        password = secrets.token_urlsafe(32)
        (RUNTIME / "admin-password").write_text(password)
        (RUNTIME / "admin-password").chmod(0o600)
        wp("core", "install", "--url=http://127.0.0.1:8089", "--title=WMOS integration tests", "--admin_user=wmos_test_admin", "--admin_email=admin@example.invalid", "--admin_password=" + password, "--skip-email")
    if not (WORDPRESS / 'wp-content/plugins/woocommerce/woocommerce.php').exists():
        wp("plugin", "install", "woocommerce", "--version=11.1.2", "--activate")
    else:
        wp('plugin','activate','woocommerce')
    wp("rewrite", "structure", "/%postname%/")
    plugin = WORDPRESS / "wp-content" / "plugins" / "woocommerce-marketing-os"
    if not plugin.exists():
        plugin.symlink_to(ROOT, target_is_directory=True)
    wp('plugin','activate','woocommerce-marketing-os')
    fences=WORDPRESS / 'wp-content/mu-plugins'
    fences.mkdir(exist_ok=True)
    (fences / 'wmos-isolated-tests.php').write_text('<?php\n// Isolated test store only.\nadd_filter("action_scheduler_allow_async_request_runner", "__return_false", PHP_INT_MAX);\nadd_filter("pre_http_request", static function ($response) { return $response === false ? new WP_Error("wmos_test_network_blocked", "External HTTP disabled in isolated tests.") : $response; }, PHP_INT_MAX);\nadd_filter("pre_wp_mail", "__return_true");\n')
    print("Isolated runtime ready; generated credentials remain in ignored .runtime files.")


if __name__ == "__main__":
    main()
