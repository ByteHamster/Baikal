<?php
#################################################################
#  Copyright notice
#
#  (c) 2013 Jérôme Schneider <mail@jeromeschneider.fr>
#  All rights reserved
#
#  http://baikal-server.com
#
#  This script is part of the Baïkal Server project. The Baïkal
#  Server project is free software; you can redistribute it
#  and/or modify it under the terms of the GNU General Public
#  License as published by the Free Software Foundation; either
#  version 2 of the License, or (at your option) any later version.
#
#  The GNU General Public License can be found at
#  http://www.gnu.org/copyleft/gpl.html.
#
#  This script is distributed in the hope that it will be useful,
#  but WITHOUT ANY WARRANTY; without even the implied warranty of
#  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
#  GNU General Public License for more details.
#
#  This copyright notice MUST APPEAR in all copies of the script!
#################################################################


namespace BaikalAdmin\Controller\Install;

class VersionUpgrade extends \Flake\Core\Controller {

    protected $aMessages = [];
    protected $oModel;
    protected $oForm;    # \Formal\Form

    protected $aErrors = [];
    protected $aSuccess = [];

    function execute() {
    }

    function render() {
        $sBigIcon = "glyph2x-magic";
        $sBaikalVersion = \Baikal\Version::VERSION;
        $sBaikalConfiguredVersion = BAIKAL_CONFIGURED_VERSION;

        if (BAIKAL_CONFIGURED_VERSION === \Baikal\Version::VERSION) {
            $sMessage = "Your system is configured to use version <strong>" . $sBaikalConfiguredVersion . "</strong>.<br />There's no upgrade to be done.";
        } else {
            $sMessage = "Upgrading Baïkal from version <strong>" . $sBaikalConfiguredVersion . "</strong> to version <strong>" . $sBaikalVersion . "</strong>";
        }

        $sHtml = <<<HTML
<header class="jumbotron subhead" id="overview">
	<h1><i class="{$sBigIcon}"></i>Baïkal upgrade wizard</h1>
	<p class="lead">{$sMessage}</p>
</header>
HTML;

        try {
            $bSuccess = $this->upgrade(BAIKAL_CONFIGURED_VERSION, \Baikal\Version::VERSION);
        } catch (\Exception $e) {
            $bSuccess = false;
            $this->aErrors[] = 'Uncaught exception during upgrade: ' . (string)$e;
        }

        if (!empty($this->aErrors)) {
            $sHtml .= "<h3>Errors</h3>" . implode("<br />\n", $this->aErrors);
        }

        if (!empty($this->aSuccess)) {
            $sHtml .= "<h3>Successful operations</h3>" . implode("<br />\n", $this->aSuccess);
        }

        if ($bSuccess === false) {
            $sHtml .= "<p>&nbsp;</p><p><span class='label label-important'>Error</span> Baïkal has not been upgraded. See the section 'Errors' for details.</p>";
        } else {
            $sHtml .= "<p>&nbsp;</p><p>Baïkal has been successfully upgraded. You may now <a class='btn btn-success' href='" . PROJECT_URI . "admin/'>Access the Baïkal admin</a></p>";
        }

        return $sHtml;
    }

    protected function upgrade($sVersionFrom, $sVersionTo) {

        if ($sVersionFrom === "0.2.0") {

            $sOldDbFilePath = PROJECT_PATH_SPECIFIC . "Db/.ht.db.sqlite";

            if (PROJECT_SQLITE_FILE === $sOldDbFilePath) {
                $sNewDbFilePath = PROJECT_PATH_SPECIFIC . "Db/db.sqlite";

                # Move old db from data/Db/.ht.db.sqlite to data/Db/db.sqlite
                if (!file_exists($sNewDbFilePath)) {
                    if (!is_writable(dirname($sNewDbFilePath))) {
                        $this->aErrors[] = "DB file path '" . dirname($sNewDbFilePath) . "' is not writable";
                        return false;
                    }

                    if (!@copy($sOldDbFilePath, $sNewDbFilePath)) {
                        $this->aErrors[] = "DB could not be copied from '" . $sOldDbFilePath . "' to '" . $sNewDbFilePath . "'.";
                        return false;
                    }

                    $this->aSuccess[] = "SQLite database has been renamed from '" . $sOldDbFilePath . "' to '" . $sNewDbFilePath . "'";
                }
            }
        }

        if (version_compare($sVersionFrom, '0.2.3', '<=')) {
            # Upgrading DB

            #	etag VARCHAR(32),
            #	size INT(11) UNSIGNED NOT NULL,
            #	componenttype VARCHAR(8),
            #	firstoccurence INT(11) UNSIGNED,
            #	lastoccurence INT(11) UNSIGNED,

            if (defined("PROJECT_DB_MYSQL") && PROJECT_DB_MYSQL === true) {
                $aSql = [
                    "ALTER TABLE calendarobjects ADD COLUMN etag VARCHAR(32)",
                    "ALTER TABLE calendarobjects ADD COLUMN size INT(11) UNSIGNED NOT NULL",
                    "ALTER TABLE calendarobjects ADD COLUMN componenttype VARCHAR(8)",
                    "ALTER TABLE calendarobjects ADD COLUMN firstoccurence INT(11) UNSIGNED",
                    "ALTER TABLE calendarobjects ADD COLUMN lastoccurence INT(11) UNSIGNED",
                    "ALTER TABLE calendars ADD COLUMN transparent TINYINT(1) NOT NULL DEFAULT '0'",
                ];

                $this->aSuccess[] = "MySQL database has been successfuly upgraded.";
            } else {
                $aSql = [
                    "ALTER TABLE calendarobjects ADD COLUMN etag text",
                    "ALTER TABLE calendarobjects ADD COLUMN size integer",
                    "ALTER TABLE calendarobjects ADD COLUMN componenttype text",
                    "ALTER TABLE calendarobjects ADD COLUMN firstoccurence integer",
                    "ALTER TABLE calendarobjects ADD COLUMN lastoccurence integer",
                    "ALTER TABLE calendars ADD COLUMN transparent bool",
                    "ALTER TABLE principals ADD COLUMN vcardurl text",    # This one is added in SQLite but not MySQL, because it is already there since the beginning in MySQL
                ];

                $this->aSuccess[] = "SQLite database has been successfuly upgraded.'";
            }

            try{
                foreach ($aSql as $sAlterTableSql) {
                    $GLOBALS["DB"]->query($sAlterTableSql);
                }
            } catch (\Exception $e) {
                $this->aSuccess = [];
                $this->aErrors[] = "<p>Database cannot be upgraded.<br />Caught exception: " . $e->getMessage() . "</p>";
                return false;
            }
        }

        $pdo = $GLOBALS['DB']->getPDO();
        if (version_compare($sVersionFrom, '0.3.0', '<')) {
            // Upgrading from sabre/dav 1.8 schema to 3.1 schema.

            if (defined("PROJECT_DB_MYSQL") && PROJECT_DB_MYSQL === true) {

                // MySQL upgrade

                // sabre/dav 2.0 changes
                foreach (['calendar', 'addressbook'] as $dataType) {

                    $tableName = $dataType . 's';
                    $pdo->exec("ALTER TABLE $tableName ADD synctoken INT(11) UNSIGNED NOT NULL DEFAULT '1'");
                    $this->aSuccess[] = 'synctoken was added to ' . $tableName;

                    $pdo->exec("ALTER TABLE $tableName DROP ctag");
                    $this->aSuccess[] = 'ctag was removed from ' . $tableName;

                    $changesTable = $dataType . 'changes';
                    $pdo->exec("
                        CREATE TABLE $changesTable (
                            id INT(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                            uri VARCHAR(200) NOT NULL,
                            synctoken INT(11) UNSIGNED NOT NULL,
                            {$dataType}id INT(11) UNSIGNED NOT NULL,
                            operation TINYINT(1) NOT NULL,
                            INDEX {$dataType}id_synctoken ({$dataType}id, synctoken)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
                    ");
                    $this->aSuccess[] = $changesTable . ' was created';

                }

                $pdo->exec("
                    CREATE TABLE calendarsubscriptions (
                        id INT(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                        uri VARCHAR(200) NOT NULL,
                        principaluri VARCHAR(100) NOT NULL,
                        source TEXT,
                        displayname VARCHAR(100),
                        refreshrate VARCHAR(10),
                        calendarorder INT(11) UNSIGNED NOT NULL DEFAULT '0',
                        calendarcolor VARCHAR(10),
                        striptodos TINYINT(1) NULL,
                        stripalarms TINYINT(1) NULL,
                        stripattachments TINYINT(1) NULL,
                        lastmodified INT(11) UNSIGNED,
                        UNIQUE(principaluri, uri)
                    );
                ");
                $this->aSuccess[] = 'calendarsubscriptions was created';

                $pdo->exec("
                    ALTER TABLE cards
                    ADD etag VARBINARY(32),
                    ADD size INT(11) UNSIGNED NOT NULL;
                ");
                $this->aSuccess[] = 'etag and size were added to cards';

                // sabre/dav 2.1 changes;
                $pdo->exec('ALTER TABLE calendarobjects ADD uid VARCHAR(200)');

                $this->aSuccess[] = 'uid was added to calendarobjects';

                $pdo->exec('
                    CREATE TABLE IF NOT EXISTS schedulingobjects
                    (
                        id INT(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                        principaluri VARCHAR(255),
                        calendardata MEDIUMBLOB,
                        uri VARCHAR(200),
                        lastmodified INT(11) UNSIGNED,
                        etag VARCHAR(32),
                        size INT(11) UNSIGNED NOT NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
                ');

                $this->aSuccess[] = 'schedulingobjects was created';

                // sabre/dav 3.0 changes
                $pdo->exec("
                    CREATE TABLE propertystorage (
                        id INT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                        path VARBINARY(1024) NOT NULL,
                        name VARBINARY(100) NOT NULL,
                        valuetype INT UNSIGNED,
                        value MEDIUMBLOB
                    );
                ");
                $pdo->exec('CREATE UNIQUE INDEX path_property ON propertystorage (path(600), name(100));');
                $this->aSuccess[] = 'propertystorage was created';

            } else {
                // SQLite upgrade

                // sabre/dav 2.0 changes
                foreach (['calendar', 'addressbook'] as $dataType) {

                    $tableName = $dataType . 's';
                    // Note: we can't remove the ctag field in sqlite :(;
                    $pdo->exec("ALTER TABLE $tableName ADD synctoken integer");
                    $this->aSuccess[] = 'synctoken was added to ' . $tableName;

                    $changesTable = $dataType . 'changes';
                    $pdo->exec("
                        CREATE TABLE $changesTable (
                            id integer primary key asc,
                            uri text,
                            synctoken integer,
                            {$dataType}id integer,
                            operation bool
                        );
                    ");
                    $this->aSuccess[] = $changesTable . ' was created';

                }
                $pdo->exec("
                    CREATE TABLE calendarsubscriptions (
                        id integer primary key asc,
                        uri text,
                        principaluri text,
                        source text,
                        displayname text,
                        refreshrate text,
                        calendarorder integer,
                        calendarcolor text,
                        striptodos bool,
                        stripalarms bool,
                        stripattachments bool,
                        lastmodified int
                    );
                ");
                $this->aSuccess[] = 'calendarsubscriptions was created';
                $pdo->exec("CREATE INDEX principaluri_uri ON calendarsubscriptions (principaluri, uri);");

                $pdo->exec("
                    ALTER TABLE cards ADD etag text;
                    ALTER TABLE cards ADD size integer;
                ");
                $this->aSuccess[] = 'etag and size were added to cards';

                // sabre/dav 2.1 changes;
                $pdo->exec('ALTER TABLE calendarobjects ADD uid TEXT');
                $this->aSuccess[] = 'uid was added to calendarobjects';

                $pdo->exec('
                    CREATE TABLE IF NOT EXISTS schedulingobjects (
                        id integer primary key asc,
                        principaluri text,
                        calendardata blob,
                        uri text,
                        lastmodified integer,
                        etag text,
                        size integer
                    )
                ');
                $this->aSuccess[] = 'schedulingobjects was created';

                // sabre/dav 3.0 changes
                $pdo->exec("
                    CREATE TABLE propertystorage (
                        id integer primary key asc,
                        path text,
                        name text,
                        valuetype integer,
                        value blob
                    );
                ");
                $pdo->exec('CREATE UNIQUE INDEX path_property ON propertystorage (path, name);');
                $this->aSuccess[] = 'propertystorage was created';


            }

            // Statements for both SQLite and MySQL
            $result = $pdo->query('SELECT id, carddata FROM cards');
            $stmt = $pdo->prepare('UPDATE cards SET etag = ?, size = ? WHERE id = ?');
            while ($row = $result->fetch(\PDO::FETCH_ASSOC)) {
                $stmt->execute([
                    md5($row['carddata']),
                    strlen($row['carddata']),
                    $row['id']
                ]);
            }
            $this->aSuccess[] = 'etag and size was recalculated for cards';
            $result = $pdo->query('SELECT id, calendardata FROM calendarobjects');
            $stmt = $pdo->prepare('UPDATE calendarobjects SET uid = ? WHERE id = ?');
            $counter = 0;

            while ($row = $result->fetch(\PDO::FETCH_ASSOC)) {

                try {
                    $vobj = \Sabre\VObject\Reader::read($row['calendardata']);
                } catch (\Exception $e) {
                    $this->aSuccess[] = 'warning: skipped record ' . $row['id'] . '. Error: ' . $e->getMessage();
                    continue;
                }
                $uid = null;
                $item = $vobj->getBaseComponent();
                if (!isset($item->UID)) {
                    $vobj->destroy();
                    continue;
                }
                $uid = (string)$item->UID;
                $stmt->execute([$uid, $row['id']]);
                $counter++;
                $vobj->destroy();

            }
            $this->aSuccess[] = 'uid was recalculated for calendarobjects';

            $result = $pdo->query('SELECT id, uri, vcardurl FROM principals WHERE vcardurl IS NOT NULL');
            $stmt1 = $pdo->prepare('INSERT INTO propertystorage (path, name, valuetype, value) VALUES (?, ?, 3, ?)');

            while ($row = $result->fetch(\PDO::FETCH_ASSOC)) {

                // Inserting the new record
                $stmt1->execute([
                    'addressbooks/' . basename($row['uri']),
                    '{http://calendarserver.org/ns/}me-card',
                    serialize(new \Sabre\DAV\Xml\Property\Href($row['vcardurl']))
                ]);

            }
            $this->aSuccess[] = 'vcardurl was migrated to the propertystorage system';

        }
        if (version_compare($sVersionFrom, '0.4.0', '<')) {

            // The sqlite schema had issues with both the calendar and
            // addressbooks tables. The tables didn't have a DEFAULT '1' for
            // the synctoken column. So we're adding it now.
            if (!defined("PROJECT_DB_MYSQL") || PROJECT_DB_MYSQL === false) {

                $pdo->exec('UPDATE calendars SET synctoken = 1 WHERE synctoken IS NULL');
                $pdo->exec('UPDATE addressbooks SET synctoken = 1 WHERE synctoken IS NULL');

                $tmpTable = '_' . time();
                $pdo->exec('ALTER TABLE calendars RENAME TO calendars' . $tmpTable);
                $pdo->exec('ALTER TABLE addressbooks RENAME TO addressbooks' . $tmpTable);

                $pdo->exec('
CREATE TABLE addressbooks (
    id integer primary key asc NOT NULL,
    principaluri text NOT NULL,
    displayname text,
    uri text NOT NULL,
    description text,
    synctoken integer DEFAULT 1 NOT NULL
);
                ');

                $pdo->exec('
CREATE TABLE calendars (
    id integer primary key asc NOT NULL,
    principaluri text NOT NULL,
    displayname text,
    uri text NOT NULL,
    synctoken integer DEFAULT 1 NOT NULL,
    description text,
    calendarorder integer,
    calendarcolor text,
    timezone text,
    components text NOT NULL,
    transparent bool
);');

                $pdo->exec('INSERT INTO calendars SELECT * FROM calendars' . $tmpTable);
                $pdo->exec('INSERT INTO addressbooks SELECT * FROM addressbooks' . $tmpTable);

                $this->aSuccess[] = 'Updated calendars and addressbooks tables';

            }

        }

        $this->updateConfiguredVersion($sVersionTo);
        return true;
    }

    protected function updateConfiguredVersion($sVersionTo) {

        # Create new settings
        $oConfig = new \Baikal\Model\Config\Standard(PROJECT_PATH_SPECIFIC . "config.php");
        $oConfig->persist();

        # Update BAIKAL_CONFIGURED_VERSION
        $oConfig = new \Baikal\Model\Config\System(PROJECT_PATH_SPECIFIC . "config.system.php");
        $oConfig->set("BAIKAL_CONFIGURED_VERSION", $sVersionTo);
        $oConfig->persist();
    }
}