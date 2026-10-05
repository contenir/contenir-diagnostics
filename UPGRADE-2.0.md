# Upgrading to 2.0

2.0 is the first tagged release. If you used the `master` branch before it,
these are the changes that can affect you.

| | before 2.0 | 2.0 |
| --- | --- | --- |
| PHP | ^8.3 | 8.3, 8.4 or 8.5 |
| symfony/console | ^6.0 \|\| ^7.0 | ^6.4.3 \|\| ^7.0.3 |
| laminas/laminas-servicemanager | ^3.0 | ^3.22 |
| laminas/laminas-diagnostics | not declared | ^1.26 |
| laminas/laminas-cache | ^3.0 | removed (unused) |
| symfony/string (via symfony/console) | any | conflicts below 6.4.3 |

```bash
composer require contenir/contenir-diagnostics:^2.0
```

The symfony/console and symfony/string minimums are the first releases
without implicitly nullable parameters, which PHP 8.4 deprecates.

If your application used laminas-cache only because this package pulled it
in, require it directly.

## Final wiring classes

`ConfigProvider`, `Command\DiagnosticsCommandFactory` and
`Command\DiagnosticsCommand` are `final`. Register your own factory instead
of extending the shipped one:

```php
// before
class MyDiagnosticsCommandFactory extends DiagnosticsCommandFactory { /* ... */ }

// 2.0
'dependencies' => [
    'factories' => [
        DiagnosticsCommand::class => MyDiagnosticsCommandFactory::class,
    ],
],
```

## Database configuration keys

The checks now read the keys Contenir CMS uses, and still accept the
laminas-db `adapters` form:

```php
// before: only this was read
'db' => ['adapters' => ['cms' => [...], 'site' => [...]]],

// 2.0: this is read first, then the form above
'db' => ['cms' => [...], 'site' => [...]],
```

If both are present for the same adapter, `db.<name>` wins. A configured
`port` is now part of the site DSN.

## Security advisories check

Without enlightn/security-checker, or without a `composer.lock` in the
working directory, the check is listed as skipped with the reason. Before,
the whole command failed with `InvalidArgumentException`. Install the
checker to enable it:

```bash
composer require --dev enlightn/security-checker
```

## bin/diagnostics arguments

The script now passes its arguments to the command, like laminas-cli does:

```bash
# before: key=value pairs, which were never used
vendor/bin/diagnostics something=1

# 2.0
vendor/bin/diagnostics --fix
```

## Adding checks: providers instead of subclasses

`DiagnosticsCommand` is final and its check groups are private. Add checks
with a `Check\CheckProviderInterface` service listed in config, and replace
the required extensions through config:

```php
// before
class SiteDiagnosticsCommand extends DiagnosticsCommand
{
    // override a check method, register under DiagnosticsCommand::class
}

// 2.0
final class SiteChecks implements CheckProviderInterface
{
    public function getChecks(): iterable
    {
        yield 'PHP Extension: imagick' => new Check\ExtensionLoaded('imagick');
    }
}

return [
    'contenir_diagnostics' => [
        'check_providers'     => [SiteChecks::class],
        'required_extensions' => ['pdo', 'pdo_sqlite', 'mbstring', 'json'],
    ],
];
```

The constructor gained an optional second argument, the providers
(`iterable<CheckProviderInterface>`); `DiagnosticsCommandFactory` fills it
from config. Built-in groups other than the extension list cannot be removed;
see the README for the checks that run.
