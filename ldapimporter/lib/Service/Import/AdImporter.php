<?php


namespace OCA\LdapImporter\Service\Import;

use OCA\LdapImporter\Service\Merge\AdUserMerger;
use OCA\LdapImporter\Service\Merge\MergerInterface;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;


/**
 * Class AdImporter
 * @package LdapImporter\Service\Import
 *
 * @author Felix Rupp <kontakt@felixrupp.com>
 * @copyright Felix Rupp
 *
 * @since 1.0.0
 */
class AdImporter implements ImporterInterface
{

    /**
     * @var boolean|resource
     */
    private $ldapConnection;

    /**
     * @var MergerInterface $merger
     */
    private $merger;

    /**
     * @var LoggerInterface $logger
     */
    private $logger;

    /**
     * @var IConfig
     */
    private $config;

    /**
     * @var string $appName
     */
    private $appName = 'ldapimporter';

    /**
     * @var IDBConnection $db
     */
    private $db;


    /**
     * AdImporter constructor.
     * @param IConfig $config
     * @param IDBConnection $db
     */
    public function __construct(IConfig $config, IDBConnection $db)
    {

        $this->config = $config;
        $this->db = $db;
    }


    /**
     * @param LoggerInterface $logger
     *
     * @throws \Exception
     */
    public function init(LoggerInterface $logger)
    {

        $this->merger = new AdUserMerger($logger);
        $this->logger = $logger;

        $this->ldapConnect();
        $this->ldapBind();

        $this->logger->info("Init complete.");
    }

    /**
     * @throws \Exception
     */
    public function close()
    {

        $this->ldapClose();
    }

    /**
     * Get User data
     *
     * @return array User data
     */
    public function getUsers()
    {

        $uidAttribute = $this->config->getAppValue($this->appName, 'cas_import_map_uid');

        $displayNameAttribute1 = $this->config->getAppValue($this->appName, 'cas_import_map_displayname');
        $displayNameAttribute2 = '';

        if (strpos($displayNameAttribute1, "+") !== FALSE) {
            $displayNameAttributes = explode("+", $displayNameAttribute1);
            $displayNameAttribute1 = $displayNameAttributes[0];
            $displayNameAttribute2 = $displayNameAttributes[1];
        }

        $emailAttribute = $this->config->getAppValue($this->appName, 'cas_import_map_email');
        $groupsAttribute = $this->config->getAppValue($this->appName, 'cas_import_map_groups');
        $regexNameUai = $this->config->getAppValue($this->appName, 'cas_import_regex_name_uai');
        $regexUaiGroup = $this->config->getAppValue($this->appName, 'cas_import_regex_uai_group');
        $regexNameGroup = $this->config->getAppValue($this->appName, 'cas_import_regex_name_group');

        $groupsFilterAttribute = json_decode($this->config->getAppValue($this->appName, 'cas_import_map_groups_fonctionel'), true);
        $arrayGroupsAttrPedagogic = json_decode($this->config->getAppValue($this->appName, 'cas_import_map_groups_pedagogic'), true);
        $quotaAttribute = $this->config->getAppValue($this->appName, 'cas_import_map_quota');
        $enableAttribute = $this->config->getAppValue($this->appName, 'cas_import_map_enabled');
        $dnAttribute = $this->config->getAppValue($this->appName, 'cas_import_map_dn');
        $mergeAttribute = boolval($this->config->getAppValue($this->appName, 'cas_import_merge'));
        $primaryAccountDnStartswWith = $this->config->getAppValue($this->appName, 'cas_import_map_dn_filter');
        $preferEnabledAccountsOverDisabled = boolval($this->config->getAppValue($this->appName, 'cas_import_merge_enabled'));
        $andEnableAttributeBitwise = $this->config->getAppValue($this->appName, 'cas_import_map_enabled_and_bitwise');

        $keep = [$uidAttribute, $displayNameAttribute1, $displayNameAttribute2, $emailAttribute, $groupsAttribute, $quotaAttribute, $enableAttribute, $dnAttribute];

        //On ajoute des nouveaux éléments qu'on récupère du ldap pour les groupe
        $keep[] = 'ESCOUAICourant';
        if (sizeof($arrayGroupsAttrPedagogic) > 0) {
            foreach ($arrayGroupsAttrPedagogic as $groupsAttrPedagogic) {
                if (array_key_exists("field", $groupsAttrPedagogic) && strlen($groupsAttrPedagogic["field"]) > 0 &&
                    array_key_exists("filter", $groupsAttrPedagogic) && strlen($groupsAttrPedagogic["filter"]) > 0 &&
                    array_key_exists("naming", $groupsAttrPedagogic) && strlen($groupsAttrPedagogic["naming"]) > 0) {
                    $keep[] = strtolower($groupsAttrPedagogic["field"]);
                }
            }
        }

        if (!$this->db->tableExists("etablissements")) {
            $sql =
                'CREATE TABLE `*PREFIX*etablissements`' .
                '(' .
                'id INT PRIMARY KEY NOT NULL AUTO_INCREMENT,' .
                'name VARCHAR(255),' .
                'uai VARCHAR(255)' .
                ')';
            $this->db->executeQuery($sql);
        }
        if (!$this->db->tableExists("asso_uai_user_group")) {
            $sql =
                'CREATE TABLE `*PREFIX*asso_uai_user_group`' .
                '(' .
                'id INT PRIMARY KEY NOT NULL AUTO_INCREMENT,' .
                'uai VARCHAR(255),' .
                'user_group VARCHAR(255)' .
                ')';
            $this->db->executeQuery($sql);
        }
        $sql = 'ALTER TABLE *PREFIX*users ADD uai_courant VARCHAR(255);';

        try {
            $this->db->executeQuery($sql);
        }
        catch (\Throwable $t) {
            $this->logger->info('Column uai_courant already exist');
        }

        $pageSize = $this->config->getAppValue($this->appName, 'cas_import_ad_sync_pagesize');

        $users = [];

        $this->logger->info("Getting all users from the AD …");

        # Get all members of the sync group
        $memberPages = $this->getLdapList($this->config->getAppValue($this->appName, 'cas_import_ad_base_dn'), $this->config->getAppValue($this->appName, 'cas_import_ad_sync_filter'), $keep, $pageSize);

        foreach ($memberPages as $memberPage) {

            #var_dump($memberPage["count"]);

            for ($key = 0; $key < $memberPage["count"]; $key++) {

                $m = $memberPage[$key];

                # Each attribute is returned as an array, the first key is [count], [0]+ will contain the actual value(s)
                $employeeID = isset($m[$uidAttribute][0]) ? $m[$uidAttribute][0] : "";
                $mail = isset($m[$emailAttribute][0]) ? $m[$emailAttribute][0] : "";
                $dn = isset($m[$dnAttribute]) ? $m[$dnAttribute] : "";

                $displayName = $employeeID;

                if (isset($m[$displayNameAttribute1][0])) {

                    $displayName = $m[$displayNameAttribute1][0];

                    if (strlen($displayNameAttribute2) > 0 && isset($m[$displayNameAttribute2][0])) {

                        $displayName .= " " . $m[$displayNameAttribute2][0];
                    }
                } else {

                    if (strlen($displayNameAttribute2) > 0 && isset($m[$displayNameAttribute2][0])) {

                        $displayName = $m[$displayNameAttribute2][0];
                    }
                }

                $quota = isset($m[$quotaAttribute][0]) ? intval($m[$quotaAttribute][0]) : 0;


                $enable = 1;

                # Shift enable attribute bytewise?
                if (isset($m[$enableAttribute][0])) {

                    if (strlen($andEnableAttributeBitwise) > 0) {

                        if (is_numeric($andEnableAttributeBitwise)) {

                            $andEnableAttributeBitwise = intval($andEnableAttributeBitwise);
                        }

                        $enable = intval((intval($m[$enableAttribute][0]) & $andEnableAttributeBitwise) == 0);
                    } else {

                        $enable = intval($m[$enableAttribute][0]);
                    }
                }

                $groupsArray = [];

                $addUser = FALSE;

                if (isset($m[strtolower($groupsAttribute)][0])) {

                    # Cycle all groups of the user
                    for ($j = 0; $j < $m[strtolower($groupsAttribute)]["count"]; $j++) {

                        # Check if user has MAP_GROUPS attribute
                        if (isset($m[strtolower(($groupsAttribute))][$j])) {

                            $addUser = TRUE; # Only add user if the group has a MAP_GROUPS attribute

                            $resultGroupsAttribute = $m[strtolower($groupsAttribute)][$j];

                            $groupName = '';

                            preg_match_all('/' . $regexNameUai . '/si', $resultGroupsAttribute, $uaiNameMatches, PREG_SET_ORDER, 0);

                            if (sizeof($uaiNameMatches) > 0 and sizeof($uaiNameMatches[0]) >= 3) {
                                //$etablissement = $this->getLdapPedagogicGroup('ou=structures,dc=esco-centre,dc=fr', '(entstructureuai=' . $uaiNameMatches[0][0] . ')');
                                $this->addEtablissementAsso($uaiNameMatches[0][intval($regexUaiGroup)], null, $uaiNameMatches[0][intval($regexNameGroup)]);
                                $this->addEtablissementAsso($uaiNameMatches[0][intval($regexUaiGroup)], null, $uaiNameMatches[0][intval($regexNameGroup)]);
                            }


                            foreach ($groupsFilterAttribute as $groupFilter) {
                                if (array_key_exists('filter', $groupFilter) && array_key_exists('naming', $groupFilter)) {
                                    if (preg_match_all("/" . $groupFilter['filter'] . "/si", $resultGroupsAttribute, $groupFilterMatches)) {
                                        if (!isset($quota) || intval($quota) < intval($groupFilter['filter'])) {
                                            $quota = $groupFilter['filter'];
                                        }
                                        $newName = $groupFilter['naming'];
                                        $regexGabarits = '/\$\{(.*?)\}/i';

                                        preg_match_all($regexGabarits, $newName, $matches, PREG_SET_ORDER, 0);
                                        $sprintfArray = [];
                                        foreach ($matches as $match) {
                                            $newName = preg_replace('/\$\{' . $match[1] . '\}/i', '%s', $newName, 1);
                                            $sprintfArray[] = $groupFilterMatches[$match[1]][0];
                                        }
                                        $groupName = sprintf($newName, ...$sprintfArray);
                                        preg_match_all('/' . $regexNameUai . '/si', $resultGroupsAttribute, $uaiNameMatches, PREG_SET_ORDER, 0);

                                        if (sizeof($uaiNameMatches) > 0 and sizeof($uaiNameMatches[0]) >= 3) {
                                            //$etablissement = $this->getLdapPedagogicGroup('ou=structures,dc=esco-centre,dc=fr', '(entstructureuai=' . $uaiNameMatches[0][0] . ')');
                                            $this->addEtablissementAsso($uaiNameMatches[0][intval($regexUaiGroup)], $groupName, $uaiNameMatches[0][intval($regexNameGroup)]);
                                            $this->addEtablissementAsso($uaiNameMatches[0][intval($regexUaiGroup)], $employeeID, $uaiNameMatches[0][intval($regexNameGroup)]);
                                        }
                                        break;
                                    }
                                    else {
                                        $this->logger->warning("Groupes fonctionels : la regex " . $groupFilter['filter'] . " ne match pas avec le groupe " . $resultGroupsAttribute);
                                    }
                                }
                            }

                            if (strlen($groupName) > 0) {
                                $this->logger->info("Groupes fonctionels : Ajout du groupe : " . $groupName);
                                $groupsArray[] = $groupName;
                            }
                        }
                    }
                }

                foreach ($arrayGroupsAttrPedagogic as $groupsAttrPedagogic) {
                    if (array_key_exists("field", $groupsAttrPedagogic) && isset($m[strtolower($groupsAttrPedagogic["field"])][0]) && strlen($groupsAttrPedagogic["field"]) > 0 &&
                        array_key_exists("filter", $groupsAttrPedagogic) && strlen($groupsAttrPedagogic["filter"]) > 0 &&
                        array_key_exists("naming", $groupsAttrPedagogic) && strlen($groupsAttrPedagogic["naming"]) > 0
                    ) {
                        $pedagogicField = $groupsAttrPedagogic["field"];

                        # Cycle all groups of the user
                        for ($j = 0; $j < $m[strtolower($pedagogicField)]["count"]; $j++) {
                            $attrPedagogicStr = $m[strtolower($pedagogicField)][$j];
                            $pedagogicFilter = $groupsAttrPedagogic["filter"];
                            $pedagogicNaming = $groupsAttrPedagogic["naming"];

                            if (preg_match_all("/" . $pedagogicFilter . "/si", $attrPedagogicStr, $groupPedagogicMatches)) {
                                # Check if user has MAP_GROUPS attribute
                                if (isset($attrPedagogicStr) && strpos($attrPedagogicStr, "$")) {
                                    $addUser = TRUE; # Only add user if the group has a MAP_GROUPS attribute
                                    $arrayGroupNamePedagogic = explode('$', $attrPedagogicStr);

                                    $groupCn = array_shift($arrayGroupNamePedagogic);

                                    # Retrieve the MAP_GROUPS_FIELD attribute of the group
                                    $groupAttr = $this->getLdapPedagogicGroup($groupCn);
                                    $groupName = '';
                                    if (array_key_exists('entstructureuai', $groupAttr) and $groupAttr['entstructureuai']['count'] > 0) {
                                        $this->addEtablissementAsso($groupAttr['entstructureuai'][0], $groupName);
                                        $this->addEtablissementAsso($groupAttr['entstructureuai'][0], $employeeID);
                                    }

                                    $regexGabarits = '/\$\{(.*?)\}/i';

                                    preg_match_all($regexGabarits, $pedagogicNaming, $matches, PREG_SET_ORDER, 0);
                                    $sprintfArray = [];
                                    foreach ($matches as $match) {

                                        $pedagogicNaming = preg_replace('/\$\{' . $match[1] . '\}/i', '%s', $pedagogicNaming, 1);
                                        if (is_numeric($match[1])) {
                                            $sprintfArray[] = $groupPedagogicMatches[$match[1]][0];
                                        }
                                        else {
                                            if (strtolower($match[1]) === 'nometablissement') {
                                                $sprintfArray[] = $this->getEstablishmentNameFromUAI($groupAttr['entstructureuai'][0]);
                                            }
                                            elseif (array_key_exists(strtolower($match[1]), $groupAttr) && $groupAttr[strtolower($match[1])]["count"] > 0) {
                                                $sprintfArray[] = $groupAttr[strtolower($match[1])][0];
                                            }
                                            else {
                                                $this->logger->warning("Groupes pédagogique : l'attibut : " . strtolower($match[1]) . " n'existe pas dans les groupe édagogique");
                                            }
                                        }
                                    }
                                    $groupName = sprintf($pedagogicNaming, ...$sprintfArray);

                                    if ($groupName && strlen($groupName) > 0) {
                                        $this->logger->info("Groupes pédagogique : Ajout du groupe : " . $groupName);
                                        $groupsArray[] = $groupName;
                                    }
                                }
                            }
                            else {
                                $this->logger->warning("Groupes pédagogique : la regex " . $pedagogicFilter . " ne match pas avec le groupe " . $attrPedagogicStr);
                            }
                        }
                    }
                }

                # Fill the users array only if we have an employeeId and addUser is true
                if (isset($employeeID) && $addUser) {
                    $this->logger->info("Groupes pédagogique : Ajout de l'utilisateur avec id  : " . $employeeID);

                    $uaiCourant = '';
                    if (array_key_exists('escouaicourant', $m) && $m['escouaicourant']['count'] > 0) {
                        $uaiCourant = $m['escouaicourant'][0];
                    }
                    $this->merger->mergeUsers($users, ['uid' => $employeeID, 'displayName' => $displayName, 'email' => $mail, 'quota' => $quota, 'groups' => $groupsArray, 'enable' => $enable, 'dn' => $dn, 'uai_courant' => $uaiCourant], $mergeAttribute, $preferEnabledAccountsOverDisabled, $primaryAccountDnStartswWith);
                }
            }
        }

        $this->logger->info("Users have been retrieved.");

        return $users;
    }

    /**
     * Ajout d'un établissement et d'une asso uai -> user/groups si il n'existe pas dans la bdd
     *
     * @param $uai
     * @return mixed|null
     */
    protected function getEstablishmentNameFromUAI($uai)
    {
        if (!is_null($uai)) {
            $qbEtablissement = $this->db->getQueryBuilder();
            $qbEtablissement->select('name')
                ->from('etablissements')
                ->where($qbEtablissement->expr()->eq('uai', $qbEtablissement->createNamedParameter($uai)))
            ;
            $result = $qbEtablissement->execute();
            $etablissement = $result->fetchAll();

            if (sizeof($etablissement) !== 0) {
                return $etablissement[0]['name'];
            }
        }
        return null;
    }

    /**
     * Ajout d'un établissement et d'une asso uai -> user/groups si il n'existe pas dans la bdd
     *
     * @param $uai
     * @param $groupUserId
     */
    protected function addEtablissementAsso($uai, $groupUserId, $name = null)
    {
        if (!is_null($name)) {
            $qbEtablissement = $this->db->getQueryBuilder();
            $qbEtablissement->select('*')
                ->from('etablissements')
                ->where($qbEtablissement->expr()->eq('uai', $qbEtablissement->createNamedParameter($uai)))
            ;
            $result = $qbEtablissement->execute();
            $etablissement = $result->fetchAll();

            if (sizeof($etablissement) === 0) {
                $insertEtablissement = $this->db->getQueryBuilder();
                $insertEtablissement->insert('etablissements')
                    ->values([
                        'uai' => $insertEtablissement->createNamedParameter($uai),
                        'name' => $insertEtablissement->createNamedParameter($name),
                    ]);
                $insertEtablissement->execute();
            }
        }

        if (!is_null($groupUserId)) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from('asso_uai_user_group')
                ->where($qb->expr()->eq('uai', $qb->createNamedParameter($uai)))
                ->andWhere($qb->expr()->eq('user_group', $qb->createNamedParameter($groupUserId)));
            $result = $qb->execute();
            $assoOaiUserGroup = $result->fetchAll();
            if (sizeof($assoOaiUserGroup) === 0) {
                $insertAsso = $this->db->getQueryBuilder();
                $insertAsso->insert('asso_uai_user_group')
                    ->values([
                        'uai' => $insertAsso->createNamedParameter($uai),
                        'user_group' => $insertAsso->createNamedParameter($groupUserId),
                    ]);
                $insertAsso->execute();
            }
        }
    }



    /**
     * List ldap entries in the base dn
     *
     * @param string $object_dn
     * @param $filter
     * @param array $keepAtributes
     * @param $pageSize
     * @return array
     */
    protected function getLdapList($object_dn, $filter, $keepAtributes, $pageSize)
    {

        $cookie = '';
        $members = [];

        do {

            // Query Group members
            ldap_control_paged_result($this->ldapConnection, $pageSize, false, $cookie);

            $results = ldap_search($this->ldapConnection, $object_dn, $filter, $keepAtributes/*, array("member;range=$range_start-$range_end")*/) or die('Error searching LDAP: ' . ldap_error($this->ldapConnection));
            $members[] = ldap_get_entries($this->ldapConnection, $results);

            ldap_control_paged_result_response($this->ldapConnection, $results, $cookie);

        } while ($cookie !== null && $cookie != '');

        // Return sorted member list
        sort($members);

        return $members;
    }


    /**
     * @param string $user_dn
     * @param bool $keep
     * @return array Attribute list
     */
    protected function getLdapAttributes($user_dn, $keep = false)
    {
        if (!isset($this->ldapConnection)) die('Error, no LDAP connection established');
        if (empty($user_dn)) die('Error, no LDAP user specified');

        // Disable pagination setting, not needed for individual attribute queries
        ldap_control_paged_result($this->ldapConnection, 1);

        // Query user attributes
        $results = (($keep) ? ldap_search($this->ldapConnection, $user_dn, 'cn=*', $keep) : ldap_search($this->ldapConnection, $user_dn, 'cn=*'));
        if (ldap_error($this->ldapConnection) == "No such object") {
            return [];
        }
        elseif (ldap_error($this->ldapConnection) != "Success") {
            die('Error searching LDAP: ' . ldap_error($this->ldapConnection) . " and " . $user_dn);
        }

        $attributes = ldap_get_entries($this->ldapConnection, $results);

        $this->logger->debug("AD attributes successfully retrieved.");

        // Return attributes list
        if (isset($attributes[0])) return $attributes[0];
        else return array();
    }

    /**
     * @param string $groupCn
     * @param string $filter
     * @param bool $keep
     * @return array Attribute list
     */
    protected function getLdapPedagogicGroup($groupCn, $filter = 'objectClass=*',$keep = false)
    {
        if (!isset($this->ldapConnection)) die('Error, no LDAP connection established');
        if (empty($groupCn)) die('Error, no LDAP user specified');

        // Disable pagination setting, not needed for individual attribute queries
        ldap_control_paged_result($this->ldapConnection, 1);

        // Query user attributes
        $results = (($keep) ? ldap_search($this->ldapConnection, $groupCn, $filter, $keep) : ldap_search($this->ldapConnection, $groupCn, $filter));
        if (ldap_error($this->ldapConnection) == "No such object") {
            return [];
        }
        elseif (ldap_error($this->ldapConnection) != "Success") {
            die('Error searching LDAP: ' . ldap_error($this->ldapConnection) . " and " . $groupCn);
        }

        $attributes = ldap_get_entries($this->ldapConnection, $results);

        $this->logger->debug("AD attributes successfully retrieved.");

        // Return attributes list
        if (isset($attributes[0])) return $attributes[0];
        else return array();
    }

    /**
     * Connect ldap
     *
     * @return bool|resource
     * @throws \Exception
     */
    protected function ldapConnect()
    {
        try {

            $host = $this->config->getAppValue($this->appName, 'cas_import_ad_host');

            $this->ldapConnection = ldap_connect($this->config->getAppValue($this->appName, 'cas_import_ad_protocol') . $host . ":" . $this->config->getAppValue($this->appName, 'cas_import_ad_port')) or die("Could not connect to " . $host);

            ldap_set_option($this->ldapConnection, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($this->ldapConnection, LDAP_OPT_REFERRALS, 0);
            ldap_set_option($this->ldapConnection, LDAP_OPT_NETWORK_TIMEOUT, 10);

            $this->logger->info("AD connected successfully.");

            return $this->ldapConnection;
        } catch (\Exception $e) {

            throw $e;
        }
    }

    /**
     * Bind ldap
     *
     * @throws \Exception
     */
    protected function ldapBind()
    {
        try {

            if ($this->ldapConnection) {
                $ldapIsBound = ldap_bind($this->ldapConnection, $this->config->getAppValue($this->appName, 'cas_import_ad_user'), $this->config->getAppValue($this->appName, 'cas_import_ad_password'));

                if (!$ldapIsBound) {

                    throw new \Exception("LDAP bind failed. Error: " . ldap_error($this->ldapConnection));
                } else {

                    $this->logger->info("AD bound successfully.");
                }
            }
        } catch (\Exception $e) {

            throw $e;
        }
    }

    /**
     * Unbind ldap
     *
     * @throws \Exception
     */
    protected function ldapUnbind()
    {

        try {

            ldap_unbind($this->ldapConnection);

            $this->logger->info("AD unbound successfully.");
        } catch (\Exception $e) {

            throw $e;
        }
    }

    /**
     * Close ldap connection
     *
     * @throws \Exception
     */
    protected function ldapClose()
    {
        try {

            ldap_close($this->ldapConnection);

            $this->logger->info("AD connection closed successfully.");
        } catch (\Exception $e) {

            throw $e;
        }
    }

    /**
     * @param array $exportData
     */
    public function exportAsCsv(array $exportData)
    {

        $this->logger->info("Exporting users to .csv …");

        $fp = fopen('accounts.csv', 'wa+');

        fputcsv($fp, ["UID", "displayName", "email", "quota", "groups", "enabled"]);

        foreach ($exportData as $fields) {

            for ($i = 0; $i < count($fields); $i++) {

                if (is_array($fields[$i])) {

                    $fields[$i] = $this->multiImplode($fields[$i], " ");
                }
            }

            fputcsv($fp, $fields);
        }

        fclose($fp);

        $this->logger->info("CSV export finished.");
    }

    /**
     * @param array $exportData
     */
    public function exportAsText(array $exportData)
    {

        $this->logger->info("Exporting users to .txt …");

        file_put_contents('accounts.txt', serialize($exportData));

        $this->logger->info("TXT export finished.");
    }

    /**
     * @param array $array
     * @param string $glue
     * @return bool|string
     */
    private function multiImplode($array, $glue)
    {
        $ret = '';

        foreach ($array as $item) {
            if (is_array($item)) {
                $ret .= $this->multiImplode($item, $glue) . $glue;
            } else {
                $ret .= $item . $glue;
            }
        }

        $ret = substr($ret, 0, 0 - strlen($glue));

        return $ret;
    }
}