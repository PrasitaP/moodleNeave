<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Testing util classes
 *
 * @package    core
 * @category   test
 * @copyright  2012 Petr Skoda {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class testing_util {
    /**
     * @var string dataroot (likely to be $CFG->dataroot).
     */
    private static $dataroot = null;

    /**
     * @var testing_data_generator
     */
    protected static $generator = null;

    /**
     * @var string current version hash from php files
     */
    protected static $versionhash = null;

    /**
     * @var array original content of all database tables
     */
    protected static $tabledata = null;

    /**
     * @var array original structure of all database tables
     */
    protected static $tablestructure = null;

    /**
     * @var array keep list of sequenceid used in a table.
     */
    private static $tablesequences = [];

    /**
     * @var array list of updated tables.
     */
    public static $tableupdated = [];

    /**
     * @var array original structure of all database tables
     */
    protected static $sequencenames = null;

    /**
     * @var string name of the json file where we store the list of dataroot files to not reset during reset_dataroot.
     */
    private static $originaldatafilesjson = 'originaldatafiles.json';

    /**
     * @var boolean set to true once $originaldatafilesjson file is created.
     */
    private static $originaldatafilesjsonadded = false;

    /**
     * @var int next sequence value for a single test cycle.
     */
    protected static $sequencenextstartingid = null;

    /**
     * Return the name of the JSON file containing the init filenames.
     *
     * @static
     * @return string
     */
    public static function get_originaldatafilesjson() {
        return self::$originaldatafilesjson;
    }

    /**
     * Return the dataroot. It's useful when mocking the dataroot when unit testing this class itself.
     *
     * @static
     * @return string the dataroot.
     */
    public static function get_dataroot() {
        global $CFG;

        // By default it's the test framework dataroot.
        if (empty(self::$dataroot)) {
            self::$dataroot = $CFG->dataroot;
        }

        return self::$dataroot;
    }

    /**
     * Set the dataroot. It's useful when mocking the dataroot when unit testing this class itself.
     *
     * @param string $dataroot the dataroot of the test framework.
     * @static
     */
    public static function set_dataroot($dataroot) {
        self::$dataroot = $dataroot;
    }

    /**
     * Returns the testing framework name
     * @static
     * @return string
     */
    final protected static function get_framework() {
        $classname = get_called_class();
        return substr($classname, 0, strpos($classname, '_'));
    }

    /**
     * Get data generator
     * @static
     * @return testing_data_generator
     */
    public static function get_data_generator() {
        if (is_null(self::$generator)) {
            require_once(__DIR__ . '/../generator/lib.php');
            self::$generator = new testing_data_generator();
        }
        return self::$generator;
    }

    /**
     * Does this site (db and dataroot) appear to be used for production?
     * We try very hard to prevent accidental damage done to production servers!!
     *
     * @static
     * @return bool
     */
    public static function is_test_site() {
        global $DB, $CFG;

        $framework = self::get_framework();

        if (!file_exists(self::get_dataroot() . '/' . $framework . 'testdir.txt')) {
            // This is already tested in bootstrap script,
            // But anyway presence of this file means the dataroot is for testing.
            return false;
        }

        $tables = $DB->get_tables(false);
        if ($tables) {
            if (!$DB->get_manager()->table_exists('config')) {
                return false;
            }
            if (!get_config('core', $framework . 'test')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns whether test database and dataroot were created using the current version codebase
     *
     * @return bool
     */
    public static function is_test_data_updated() {
        global $DB;

        $framework = self::get_framework();

        $datarootpath = self::get_dataroot() . '/' . $framework;
        if (!file_exists($datarootpath . '/tabledata.ser') || !file_exists($datarootpath . '/tablestructure.ser')) {
            return false;
        }

        if (!file_exists($datarootpath . '/versionshash.txt')) {
            return false;
        }

        $hash = core_component::get_all_versions_hash();
        $oldhash = file_get_contents($datarootpath . '/versionshash.txt');

        if ($hash !== $oldhash) {
            return false;
        }

        // A direct database request must be used to avoid any possible caching of an older value.
        $dbhash = $DB->get_field('config', 'value', ['name' => $framework . 'test']);
        if ($hash !== $dbhash) {
            return false;
        }

        return true;
    }

    /**
     * Stores the status of the database
     *
     * Serializes the contents and the structure and
     * stores it in the test framework space in dataroot
     */
    protected static function store_database_state() {
        global $DB, $CFG;

        $framework = self::get_framework();

        // Store data for all tables.
        $data = [];
        $structure = [];
        $tables = $DB->get_tables();
        foreach ($tables as $table) {
            $columns = $DB->get_columns($table);
            $structure[$table] = $columns;
            if (isset($columns['id']) && $columns['id']->auto_increment) {
                $data[$table] = $DB->get_records($table, [], 'id ASC');
            } else {
                // There should not be many of these.
                $data[$table] = $DB->get_records($table, []);
            }
        }
        $data = serialize($data);
        $datafile = self::get_dataroot() . '/' . $framework . '/tabledata.ser';
        file_put_contents($datafile, $data);
        testing_fix_file_permissions($datafile);

        $structure = serialize($structure);
        $structurefile = self::get_dataroot() . '/' . $framework . '/tablestructure.ser';
        file_put_contents($structurefile, $structure);
        testing_fix_file_permissions($structurefile);
    }

    /**
     * Stores the version hash in both database and dataroot
     */
    protected static function store_versions_hash() {
        global $CFG;

        $framework = self::get_framework();
        $hash = core_component::get_all_versions_hash();

        // Add test db flag.
        set_config($framework . 'test', $hash);

        // Hash all plugin versions - helps with very fast detection of db structure changes.
        $hashfile = self::get_dataroot() . '/' . $framework . '/versionshash.txt';
        file_put_contents($hashfile, $hash);
        testing_fix_file_permissions($hashfile);
    }

    /**
     * Returns contents of all tables right after installation.
     * @static
     * @return array  $table=>$records
     */
    protected static function get_tabledata() {
        if (!isset(self::$tabledata)) {
            $framework = self::get_framework();

            $datafile = self::get_dataroot() . '/' . $framework . '/tabledata.ser';
            if (!file_exists($datafile)) {
                // Not initialised yet.
                return [];
            }

            $data = file_get_contents($datafile);
            self::$tabledata = unserialize($data);
        }

        if (!is_array(self::$tabledata)) {
            testing_error(
                1,
                'Can not read dataroot/' . $framework . '/tabledata.ser or invalid format, reinitialize test database.',
            );
        }

        return self::$tabledata;
    }

    /**
     * Returns structure of all tables right after installation.
     * @static
     * @return array $table=>$records
     */
    public static function get_tablestructure() {
        if (!isset(self::$tablestructure)) {
            $framework = self::get_framework();

            $structurefile = self::get_dataroot() . '/' . $framework . '/tablestructure.ser';
            if (!file_exists($structurefile)) {
                // Not initialised yet.
                return [];
            }

            $data = file_get_contents($structurefile);
            self::$tablestructure = unserialize($data);
        }

        if (!is_array(self::$tablestructure)) {
            testing_error(
                1,
                "Can not read dataroot/{$framework}/tablestructure.ser or invalid format, reinitialize test database.",
            );
        }

        return self::$tablestructure;
    }

    /**
     * Returns the names of sequences for each autoincrementing id field in all standard tables.
     * @static
     * @return array $table=>$sequencename
     */
    public static function get_sequencenames() {
        global $DB;

        if (isset(self::$sequencenames)) {
            return self::$sequencenames;
        }

        if (!$structure = self::get_tablestructure()) {
            return [];
        }

        self::$sequencenames = [];
        foreach ($structure as $table => $ignored) {
            $name = $DB->get_manager()->generator->getSequenceFromDB(new xmldb_table($table));
            if ($name !== false) {
                self::$sequencenames[$table] = $name;
            }
        }

        return self::$sequencenames;
    }

    /**
     * Returns list of tables that are unmodified and empty.
     *
     * @static
     * @return array of table names, empty if unknown
     */
    protected static function guess_unmodified_empty_tables() {
        global $DB;

        $dbfamily = $DB->get_dbfamily();

        if ($dbfamily === 'mysql') {
            $empties = [];
            $prefix = $DB->get_prefix();
            $rs = $DB->get_recordset_sql("SHOW TABLE STATUS LIKE ?", [$prefix . '%']);
            foreach ($rs as $info) {
                $table = strtolower($info->name);
                if (strpos($table, $prefix) !== 0) {
                    // Incorrect table match caused by _.
                    continue;
                }

                if (!is_null($info->auto_increment) && $info->rows == 0 && ($info->auto_increment == 1)) {
                    $table = preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $table);
                    $empties[$table] = $table;
                }
            }
            $rs->close();
            return $empties;
        } else if ($dbfamily === 'mssql') {
            $empties = [];
            $prefix = $DB->get_prefix();
            $sql = "SELECT t.name
                      FROM sys.identity_columns i
                      JOIN sys.tables t ON t.object_id = i.object_id
                     WHERE t.name LIKE ?
                       AND i.name = 'id'
                       AND i.last_value IS NULL";
            $rs = $DB->get_recordset_sql($sql, [$prefix . '%']);
            foreach ($rs as $info) {
                $table = strtolower($info->name);
                if (strpos($table, $prefix) !== 0) {
                    // Incorrect table match caused by _.
                    continue;
                }
                $table = preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $table);
                $empties[$table] = $table;
            }
            $rs->close();
            return $empties;
        } else {
            return [];
        }
    }

    /**
     * Determine the next unique starting id sequences.
     *
     * @static
     * @param array $records The records to use to determine the starting value for the table.
     * @param string $table table name.
     * @return int The value the sequence should be set to.
     */
    private static function get_next_sequence_starting_value($records, $table) {
        if (isset(self::$tablesequences[$table])) {
            return self::$tablesequences[$table];
        }

        $id = self::$sequencenextstartingid;

        // If there are records, calculate the minimum id we can use.
        // It must be bigger than the last record's id.
        if (!empty($records)) {
            $lastrecord = end($records);
            $id = max($id, $lastrecord->id + 1);
        }

        self::$sequencenextstartingid = $id + 1000;

        self::$tablesequences[$table] = $id;

        return $id;
    }

    /**
     * Reset all database sequences to initial values.
     *
     * @static
     * @param array $empties tables that are known to be unmodified and empty
     * @return void
     */
    public static function reset_all_database_sequences(?array $empties = null) {
        global $DB;

        if (!$data = self::get_tabledata()) {
            // Not initialised yet.
            return;
        }
        if (!$structure = self::get_tablestructure()) {
            // Not initialised yet.
            return;
        }

        $updatedtables = self::$tableupdated;

        // If all starting Id's are the same, it's difficult to detect coding and testing
        // errors that use the incorrect id in tests.  The classic case is cmid vs instance id.
        // To reduce the chance of the coding error, we start sequences at different values where possible.
        // In a attempt to avoid tables with existing id's we start at a high number.
        // Reset the value each time all database sequences are reset.
        if (defined('PHPUNIT_SEQUENCE_START') && PHPUNIT_SEQUENCE_START) {
            self::$sequencenextstartingid = PHPUNIT_SEQUENCE_START;
        } else {
            self::$sequencenextstartingid = 100000;
        }

        $dbfamily = $DB->get_dbfamily();
        if ($dbfamily === 'postgres') {
            $queries = [];
            $prefix = $DB->get_prefix();
            foreach ($data as $table => $records) {
                // If table is not modified then no need to do anything.
                if (!isset($updatedtables[$table])) {
                    continue;
                }
                if (isset($structure[$table]['id']) && $structure[$table]['id']->auto_increment) {
                    $nextid = self::get_next_sequence_starting_value($records, $table);
                    $queries[] = "ALTER SEQUENCE {$prefix}{$table}_id_seq RESTART WITH $nextid";
                }
            }
            if ($queries) {
                $DB->change_database_structure(implode(';', $queries));
            }
        } else if ($dbfamily === 'mysql') {
            $queries = [];
            $sequences = [];
            $prefix = $DB->get_prefix();
            $rs = $DB->get_recordset_sql("SHOW TABLE STATUS LIKE ?", [$prefix . '%']);
            foreach ($rs as $info) {
                $table = strtolower($info->name);
                if (strpos($table, $prefix) !== 0) {
                    // Incorrect table match caused by _.
                    continue;
                }
                if (!is_null($info->auto_increment)) {
                    $table = preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $table);
                    $sequences[$table] = $info->auto_increment;
                }
            }
            $rs->close();
            $prefix = $DB->get_prefix();
            foreach ($data as $table => $records) {
                // If table is not modified then no need to do anything.
                if (!isset($updatedtables[$table])) {
                    continue;
                }
                if (isset($structure[$table]['id']) && $structure[$table]['id']->auto_increment) {
                    if (isset($sequences[$table])) {
                        $nextid = self::get_next_sequence_starting_value($records, $table);
                        if ($sequences[$table] != $nextid) {
                            $queries[] = "ALTER TABLE {$prefix}{$table} AUTO_INCREMENT = $nextid";
                        }
                    } else {
                        // Some problem exists, fallback to standard code.
                        $DB->get_manager()->reset_sequence($table);
                    }
                }
            }
            if ($queries) {
                $DB->change_database_structure(implode(';', $queries));
            }
        } else {
            // Note: does mssql support any kind of faster reset?
            // This also implies mssql will not use unique sequence values.
            if (is_null($empties) && (empty($updatedtables))) {
                $empties = self::guess_unmodified_empty_tables();
            }
            foreach ($data as $table => $records) {
                // If table is not modified then no need to do anything.
                if (isset($empties[$table]) || (!isset($updatedtables[$table]))) {
                    continue;
                }
                if (isset($structure[$table]['id']) && $structure[$table]['id']->auto_increment) {
                    $DB->get_manager()->reset_sequence($table);
                }
            }
        }
    }

    /**
     * Reset all database tables to default values.
     * @static
     * @return bool true if reset done, false if skipped
     */
    public static function reset_database() {
        global $DB;

        $tables = $DB->get_tables(false);
        if (!$tables || empty($tables['config'])) {
            // Not installed yet.
            return false;
        }

        if (!$data = self::get_tabledata()) {
            // Not initialised yet.
            return false;
        }
        if (!$structure = self::get_tablestructure()) {
            // Not initialised yet.
            return false;
        }

        $empties = [];
        // Use local copy of self::$tableupdated, as list gets updated in for loop.
        $updatedtables = self::$tableupdated;

        // If empty tablesequences list then it's the very first run.
        if (empty(self::$tablesequences) && (($DB->get_dbfamily() != 'mysql') && ($DB->get_dbfamily() != 'postgres'))) {
            // Only Mysql and Postgres support random sequence, so don't guess, just reset everything on very first run.
            $empties = self::guess_unmodified_empty_tables();
        }

        // Check if any table has been modified by behat selenium process.
        if (defined('BEHAT_SITE_RUNNING')) {
            // Crazy way to reset :(.
            $tablesupdatedfile = self::get_tables_updated_by_scenario_list_path();
            if ($tablesupdated = @json_decode(file_get_contents($tablesupdatedfile), true)) {
                self::$tableupdated = array_merge(self::$tableupdated, $tablesupdated);
                unlink($tablesupdatedfile);
            }
            $updatedtables = self::$tableupdated;
        }

        foreach ($data as $table => $records) {
            // If table is not modified then no need to do anything.
            // $updatedtables tables is set after the first run, so check before checking for specific table update.
            if (!empty($updatedtables) && !isset($updatedtables[$table])) {
                continue;
            }

            if (empty($records)) {
                if (!isset($empties[$table])) {
                    // Table has been modified and is not empty.
                    $DB->delete_records($table, []);
                }
                continue;
            }

            if (isset($structure[$table]['id']) && $structure[$table]['id']->auto_increment) {
                $currentrecords = $DB->get_records($table, [], 'id ASC');
                $changed = false;
                foreach ($records as $id => $record) {
                    if (!isset($currentrecords[$id])) {
                        $changed = true;
                        break;
                    }
                    if ((array)$record != (array)$currentrecords[$id]) {
                        $changed = true;
                        break;
                    }
                    unset($currentrecords[$id]);
                }
                if (!$changed) {
                    if ($currentrecords) {
                        $lastrecord = end($records);
                        $DB->delete_records_select($table, "id > ?", [$lastrecord->id]);
                        continue;
                    } else {
                        continue;
                    }
                }
            }

            $DB->delete_records($table, []);
            foreach ($records as $record) {
                $DB->import_record($table, $record, false, true);
            }
        }

        // Reset all next record ids - aka sequences.
        self::reset_all_database_sequences($empties);

        // Remove extra tables.
        foreach ($tables as $table) {
            if (!isset($data[$table])) {
                $DB->get_manager()->drop_table(new xmldb_table($table));
            }
        }

        self::reset_updated_table_list();

        return true;
    }

    /**
     * Purge dataroot directory
     * @static
     * @return void
     */
    public static function reset_dataroot() {
        global $CFG;

        $childclassname = self::get_framework() . '_util';

        // Do not delete automatically installed files.
        self::skip_original_data_files($childclassname);

        // Clear file status cache, before checking file_exists.
        clearstatcache();

        // Clean up the dataroot folder.
        $handle = opendir(self::get_dataroot());
        while (false !== ($item = readdir($handle))) {
            if (in_array($item, $childclassname::$datarootskiponreset)) {
                continue;
            }
            if (is_dir(self::get_dataroot() . "/$item")) {
                remove_dir(self::get_dataroot() . "/$item", false);
            } else {
                unlink(self::get_dataroot() . "/$item");
            }
        }
        closedir($handle);

        // Clean up the dataroot/filedir folder.
        if (file_exists(self::get_dataroot() . '/filedir')) {
            $handle = opendir(self::get_dataroot() . '/filedir');
            while (false !== ($item = readdir($handle))) {
                if (in_array('filedir' . DIRECTORY_SEPARATOR . $item, $childclassname::$datarootskiponreset)) {
                    continue;
                }
                if (is_dir(self::get_dataroot() . "/filedir/$item")) {
                    remove_dir(self::get_dataroot() . "/filedir/$item", false);
                } else {
                    unlink(self::get_dataroot() . "/filedir/$item");
                }
            }
            closedir($handle);
        }

        make_temp_directory('');
        make_backup_temp_directory('');
        make_cache_directory('');
        make_localcache_directory('');
        // Purge all data from the caches. This is required for consistency between tests.
        // Any file caches that happened to be within the data root will have already been clearer (because we just deleted cache)
        // and now we will purge any other caches as well.  This must be done before the cache_factory::reset() as that
        // removes all definitions of caches and purge does not have valid caches to operate on.
        cache_helper::purge_all();
        // Reset the cache API so that it recreates it's required directories as well.
        cache_factory::reset();
    }

    /**
     * Gets a text-based site version description.
     *
     * @return string The site info
     */
    public static function get_site_info() {
        global $CFG;

        $output = '';

        // All developers have to understand English, do not localise!
        $env = self::get_environment();

        $output .= "Moodle " . $env['moodleversion'];
        if ($hash = self::get_git_hash()) {
            $output .= ", $hash";
        }
        $output .= "\n";

        // Add php version.
        require_once($CFG->libdir . '/environmentlib.php');
        $output .= "Php: " . normalize_version($env['phpversion']);

        // Add database type and version.
        $output .= ", " . $env['dbtype'] . ": " . $env['dbversion'];

        // OS details.
        $output .= ", OS: " . $env['os'] . "\n";

        return $output;
    }

    /**
     * Try to get current git hash of the Moodle in $CFG->dirroot.
     * @return string null if unknown, sha1 hash if known
     */
    public static function get_git_hash() {
        global $CFG;

        // This is a bit naive, but it should mostly work for all platforms.

        if (!file_exists("$CFG->dirroot/.git/HEAD")) {
            return null;
        }

        $headcontent = file_get_contents("$CFG->dirroot/.git/HEAD");
        if ($headcontent === false) {
            return null;
        }

        $headcontent = trim($headcontent);

        // If it is pointing to a hash we return it directly.
        if (strlen($headcontent) === 40) {
            return $headcontent;
        }

        if (strpos($headcontent, 'ref: ') !== 0) {
            return null;
        }

        $ref = substr($headcontent, 5);

        if (!file_exists("$CFG->dirroot/.git/$ref")) {
            return null;
        }

        $hash = file_get_contents("$CFG->dirroot/.git/$ref");

        if ($hash === false) {
            return null;
        }

        $hash = trim($hash);

        if (strlen($hash) != 40) {
            return null;
        }

        return $hash;
    }

    /**
     * Set state of modified tables.
     *
     * @param string $sql sql which is updating the table.
     */
    public static function set_table_modified_by_sql($sql) {
        global $DB;

        $prefix = $DB->get_prefix();

        preg_match('/( ' . $prefix . '\w*)(.*)/', $sql, $matches);
        // Ignore random sql for testing like "XXUPDATE SET XSSD".
        if (!empty($matches[1])) {
            $table = trim($matches[1]);
            $table = preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $table);
            self::$tableupdated[$table] = true;

            if (defined('BEHAT_SITE_RUNNING')) {
                $tablesupdatedfile = self::get_tables_updated_by_scenario_list_path();
                $tablesupdated = @json_decode(file_get_contents($tablesupdatedfile), true);
                if (!isset($tablesupdated[$table])) {
                    $tablesupdated[$table] = true;
                    @file_put_contents($tablesupdatedfile, json_encode($tablesupdated, JSON_PRETTY_PRINT));
                }
            }
        }
    }

    /**
     * Reset updated table list. This should be done after every reset.
     */
    public static function reset_updated_table_list() {
        self::$tableupdated = [];
    }

    /**
     * Delete tablesupdatedbyscenario file. This should be called before suite,
     * to ensure full db reset.
     */
    public static function clean_tables_updated_by_scenario_list() {
        $tablesupdatedfile = self::get_tables_updated_by_scenario_list_path();
        if (file_exists($tablesupdatedfile)) {
            unlink($tablesupdatedfile);
        }

        // Reset static cache of cli process.
        self::reset_updated_table_list();
    }

    /**
     * Returns the path to the file which holds list of tables updated in scenario.
     * @return string
     */
    final protected static function get_tables_updated_by_scenario_list_path() {
        return self::get_dataroot() . '/tablesupdatedbyscenario.json';
    }

    /**
     * Drop the whole test database
     * @static
     * @param bool $displayprogress
     */
    protected static function drop_database($displayprogress = false) {
        global $DB;

        $tables = $DB->get_tables(false);
        if (isset($tables['config'])) {
            // Config always last to prevent problems with interrupted drops!
            unset($tables['config']);
            $tables['config'] = 'config';
        }

        if ($displayprogress) {
            echo "Dropping tables:\n";
        }
        $dotsonline = 0;
        foreach ($tables as $tablename) {
            $table = new xmldb_table($tablename);
            $DB->get_manager()->drop_table($table);

            if ($dotsonline == 60) {
                if ($displayprogress) {
                    echo "\n";
                }
                $dotsonline = 0;
            }
            if ($displayprogress) {
                echo '.';
            }
            $dotsonline += 1;
        }
        if ($displayprogress) {
            echo "\n";
        }
    }

    /**
     * Drops the test framework dataroot
     * @static
     */
    protected static function drop_dataroot() {
        global $CFG;

        $framework = self::get_framework();
        $childclassname = $framework . '_util';

        $files = scandir(self::get_dataroot() . '/'  . $framework);
        foreach ($files as $file) {
            if (in_array($file, $childclassname::$datarootskipondrop)) {
                continue;
            }
            $path = self::get_dataroot() . '/' . $framework . '/' . $file;
            if (is_dir($path)) {
                remove_dir($path, false);
            } else {
                unlink($path);
            }
        }

        $jsonfilepath = self::get_dataroot() . '/' . self::$originaldatafilesjson;
        if (file_exists($jsonfilepath)) {
            // Delete the json file.
            unlink($jsonfilepath);
            // Delete the dataroot filedir.
            remove_dir(self::get_dataroot() . '/filedir', false);
        }
    }

    /**
     * Skip the original dataroot files to not been reset.
     *
     * @static
     * @param string $utilclassname the util class name..
     */
    protected static function skip_original_data_files($utilclassname) {
        $jsonfilepath = self::get_dataroot() . '/' . self::$originaldatafilesjson;
        if (file_exists($jsonfilepath)) {
            $listfiles = file_get_contents($jsonfilepath);

            // Mark each files as to not be reset.
            if (!empty($listfiles) && !self::$originaldatafilesjsonadded) {
                $originaldatarootfiles = json_decode($listfiles);
                // Keep the json file. Only drop_dataroot() should delete it.
                $originaldatarootfiles[] = self::$originaldatafilesjson;
                $utilclassname::$datarootskiponreset = array_merge(
                    $utilclassname::$datarootskiponreset,
                    $originaldatarootfiles
                );
                self::$originaldatafilesjsonadded = true;
            }
        }
    }

    /**
     * Save the list of the original dataroot files into a json file.
     */
    protected static function save_original_data_files() {
        global $CFG;

        $jsonfilepath = self::get_dataroot() . '/' . self::$originaldatafilesjson;

        // Save the original dataroot files if not done (only executed the first time).
        if (!file_exists($jsonfilepath)) {
            $listfiles = [];
            $currentdir = 'filedir' . DIRECTORY_SEPARATOR . '.';
            $parentdir = 'filedir' . DIRECTORY_SEPARATOR . '..';
            $listfiles[$currentdir] = $currentdir;
            $listfiles[$parentdir] = $parentdir;

            $filedir = self::get_dataroot() . '/filedir';
            if (file_exists($filedir)) {
                $directory = new RecursiveDirectoryIterator($filedir);
                foreach (new RecursiveIteratorIterator($directory) as $file) {
                    if ($file->isDir()) {
                        $key = substr($file->getPath(), strlen(self::get_dataroot() . '/'));
                    } else {
                        $key = substr($file->getPathName(), strlen(self::get_dataroot() . '/'));
                    }
                    $listfiles[$key] = $key;
                }
            }

            // Save the file list in a JSON file.
            $fp = fopen($jsonfilepath, 'w');
            fwrite($fp, json_encode(array_values($listfiles)));
            fclose($fp);
        }
    }

    /**
     * Return list of environment versions on which tests will run.
     * Environment includes:
     * - moodleversion
     * - phpversion
     * - dbtype
     * - dbversion
     * - os
     *
     * @return array
     */
    public static function get_environment() {
        global $CFG, $DB;

        $env = [];

        // Add moodle version.
        $release = null;
        require("$CFG->dirroot/version.php");
        $env['moodleversion'] = $release;

        // Add php version.
        $phpversion = phpversion();
        $env['phpversion'] = $phpversion;

        // Add database type and version.
        $dbtype = $CFG->dbtype;
        $dbinfo = $DB->get_server_info();
        $dbversion = $dbinfo['version'];
        $env['dbtype'] = $dbtype;
        $env['dbversion'] = $dbversion;

        // OS details.
        $osdetails = php_uname('s') . " " . php_uname('r') . " " . php_uname('m');
        $env['os'] = $osdetails;

        return $env;
    }
}
