# contenir/contenir-diagnostics

[![Continuous Integration](https://github.com/contenir/contenir-diagnostics/actions/workflows/continuous-integration.yml/badge.svg)](https://github.com/contenir/contenir-diagnostics/actions/workflows/continuous-integration.yml)
[![codecov](https://codecov.io/gh/contenir/contenir-diagnostics/graph/badge.svg)](https://codecov.io/gh/contenir/contenir-diagnostics)

A `diagnostics` console command for [Contenir CMS](https://github.com/contenir)
sites on Mezzio or laminas-mvc. It checks the PHP version and extensions,
writable directories, configuration files, database connectivity, security
advisories and disk space, and with `--fix` repairs the common problems
(missing directories, permissions, a missing `config/autoload/local.php`).
It is built on [laminas-diagnostics](https://docs.laminas.dev/laminas-diagnostics/)
and symfony/console.

## Requirements

- PHP 8.3, 8.4 or 8.5
- laminas/laminas-diagnostics 1.26+
- symfony/console 6.4.3+ or 7.0.3+
- Optional: [laminas/laminas-cli](https://docs.laminas.dev/laminas-cli/) to run
  it as `vendor/bin/laminas diagnostics`, and
  [enlightn/security-checker](https://github.com/enlightn/security-checker)
  for the security advisories check

There is no earlier tagged release; see [UPGRADE-2.0.md](UPGRADE-2.0.md) for
changes from the `master` branch before 2.0.

## Install

```bash
composer require contenir/contenir-diagnostics
```

With [laminas-component-installer](https://docs.laminas.dev/laminas-component-installer/)
the `Contenir\Diagnostics\ConfigProvider` is added to your configuration
automatically. It registers the `DiagnosticsCommand` service and the
laminas-cli command name `diagnostics`.

## Usage

Run it from the application root; every path is relative to the working
directory.

```bash
vendor/bin/laminas diagnostics          # check only
vendor/bin/laminas diagnostics --fix    # check, then repair what it can (-f)
vendor/bin/diagnostics [--fix]          # without laminas-cli; needs config/container.php
```

The command exits with `0` when nothing failed (warnings and skips are
allowed) and `1` when any check failed.

### Checks

| Group | Checks | Constant |
| --- | --- | --- |
| PHP environment | PHP >= 8.3; extensions loaded | `REQUIRED_EXTENSIONS`: pdo, pdo_mysql, pdo_sqlite, gd, mbstring, json, intl, fileinfo, zip |
| Directories | exist and are writable | `WRITABLE_DIRECTORIES`: data, data/cache, data/cache/laminas, data/logs |
| Configuration | files exist | `REQUIRED_CONFIG_FILES`: config/autoload/local.php, config/autoload/cache.global.php |
| Databases | CMS SQLite file exists; site MySQL accepts a connection | Only when configured, see below |
| Security | no known advisories for `composer.lock` | Skipped, with the reason, without enlightn/security-checker or a `composer.lock` |
| Disk space | at least 100 MB free | `MINIMUM_FREE_DISK_SPACE` |

### Auto-fix

`--fix` runs a first pass over the directories and configuration files
before the checks:

- a missing directory is created (0755, recursively);
- a read-only directory is changed to 0755;
- a missing `config/autoload/local.php` is written with
  `ConfigAggregator::ENABLE_CACHE => false`;
- other missing config files are reported as needing manual setup.

The output ends with how many repairs worked and how many did not.

### Adding checks

`DiagnosticsCommand` is `final`. Add site-specific checks with a
`Check\CheckProviderInterface` service, returning laminas-diagnostics checks
keyed by the label to show, and list the service under
`contenir_diagnostics.check_providers`. Provider checks run after the
built-in ones and count towards the exit code.

```php
use Contenir\Diagnostics\Check\CheckProviderInterface;
use Laminas\Diagnostics\Check;

final class SiteChecks implements CheckProviderInterface
{
    public function getChecks(): iterable
    {
        yield 'PHP Extension: imagick' => new Check\ExtensionLoaded('imagick');
        yield 'Writable: public/asset' => new Check\DirWritable('public/asset');
    }
}

// config/autoload/diagnostics.global.php
return [
    'contenir_diagnostics' => [
        'check_providers'     => [SiteChecks::class],
        'required_extensions' => ['pdo', 'pdo_sqlite', 'mbstring', 'json'], // replaces REQUIRED_EXTENSIONS
    ],
    'dependencies' => [
        'invokables' => [SiteChecks::class => SiteChecks::class],
    ],
];
```

`required_extensions` replaces the default extension list when set (for
sites without MySQL, GD or intl, for example). A provider service that does
not implement the interface makes the factory throw
`InvalidArgumentException`.

## Configuration

The command reads database adapters from the application config, under
`db.<name>` (as Contenir CMS and contenir-setup write them) or
`db.adapters.<name>`:

```php
'db' => [
    'cms'  => ['database' => 'data/cms/cms.db'],          // default data/database.sqlite
    'site' => [
        'hostname' => 'localhost',                         // default localhost
        'port'     => 3306,                                // optional
        'database' => 'site',
        'username' => 'site',
        'password' => '...',
    ],
],
```

A database check runs only when its adapter is configured.

| Key | Default | Purpose |
| --- | --- | --- |
| `contenir_diagnostics.check_providers` | `[]` | Service names of `CheckProviderInterface` implementations |
| `contenir_diagnostics.required_extensions` | `REQUIRED_EXTENSIONS` | PHP extensions to require, replacing the default list |

## Development

The QA toolchain is [php-db/phpdb-qa-tools](https://github.com/php-db/phpdb-qa-tools).
[Mago](https://mago.carthage.software/) is a standalone binary, installed
separately (`brew install mago`).

```bash
composer check             # everything below
composer cs-check          # mago format --check && mago lint
composer static-analysis   # mago analyze
composer test              # unit suite: configuration and command definition, no I/O
composer test-integration  # integration suite: the command and bin script in a temp application root
composer test-coverage     # both suites, clover.xml for Codecov
```

The integration suite never contacts the advisories service. Its MySQL check
connects to `127.0.0.1` and expects the connection to fail. Permission tests
are skipped when run as root.

## License

MIT. See [LICENSE](LICENSE).
