# MassRollback

A MediaWiki extension for rolling back all of a user's edits within a date range in one go, instead of page by page.

## How it works

Enter a username and a date range. The extension finds every page where that user's edit is still the current revision within that range, and shows you the list before doing anything. Uncheck any pages you don't want touched, then confirm.

Pages someone else has since edited are left alone; they're excluded from the list automatically.

## Pages

- **Special:MassRollback**: find and roll back a user's edits. Only available to users with the `massrollback` permission. Also adds a "mass rollback" link to that user's tool-links bar on Special:Contributions, pre-filling the username.

## Permissions

Rolling back also requires MediaWiki's own `rollback` right, in addition to `massrollback`.

The `massrollback` permission is needed to use this extension. By default it's given to a new `massrollback-manager` group, so you can add trusted users to that group without giving them full admin rights.

If you'd rather use an existing group, add a line like this to your wiki's `LocalSettings.php`:

```php
$wgGroupPermissions['sysop']['massrollback'] = true;
```

## Settings

- `$wgMassRollbackMaxPages`: the most pages listed and processed in one go. Defaults to `500`.

## Structure

```
MassRollback/
├── extension.json
├── MassRollback.alias.php
├── includes/
│   ├── Hooks.php
│   └── SpecialMassRollback.php
├── resources/
│   └── ext.massrollback.styles.less
├── i18n/
│   ├── en.json
│   └── qqq.json
├── LICENSE
└── README.md
```

## License

MIT
