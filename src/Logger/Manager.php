<?php

namespace Bolt\Logger;

use Doctrine\DBAL\Connection as DoctrineConn;

use Monolog\Logger;

use Bolt\Application;
use Bolt\Helpers\String;
use Bolt\Pager;

/**
 *
 *
 * @author Gawain Lynch <gawain.lynch@gmail.com>
 */
class Manager
{
    /**
     * @var Application
     */
    private $app;

    /**
     * @var boolean
     */
    private $initialized = false;

    /**
     * @var string
     */
    private $tablename;

    /**
     *
     * @param Application $app
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function trim()
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        $query = sprintf(
            "DELETE FROM %s WHERE level='1';",
            $this->tablename
        );
        $this->app['db']->executeQuery($query);

        $query = sprintf(
            "DELETE FROM %s WHERE level='2' AND date < ?;",
            $this->tablename
        );

        $this->app['db']->executeQuery(
            $query,
            array(date('Y-m-d H:i:s', strtotime('-2 day'))),
            array(\PDO::PARAM_STR)
        );

        $query = sprintf(
            "DELETE FROM %s WHERE date < ?;",
            $this->tablename
        );
        $this->app['db']->executeQuery(
            $query,
            array(date('Y-m-d H:i:s', strtotime('-7 day'))),
            array(\PDO::PARAM_STR)
        );
    }

    public function clear()
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        $configdb = $this->app['config']->getDBOptions();

        if (isset($configdb['driver']) && ($configdb['driver'] == "pdo_sqlite")) {

            // sqlite
            $query = sprintf(
                "DELETE FROM %s; UPDATE SQLITE_SEQUENCE SET seq = 0 WHERE name = '%s'",
                $this->tablename,
                $this->tablename
            );

        } else {

            // mysql and pgsql the same
            $query = sprintf(
                'TRUNCATE %s;',
                $this->tablename
            );

        }

        $this->app['db']->executeQuery($query);
    }

    public function getActivity($amount = 10, $minlevel = 1)
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        $context = array('save content', 'login', 'logout', 'fixme', 'user');

        $param = Pager::makeParameterId('activity');
        /* @var $query \Symfony\Component\HttpFoundation\ParameterBag */
        $query = $this->app['request']->query;
        $page = ($query) ? $query->get($param, $query->get('page', 1)) : 1;

        $query = sprintf(
            "SELECT * FROM %s WHERE context IN (?) OR (level >= ?) ORDER BY id DESC",
            $this->tablename
        );
        $query = $this->app['db']->getDatabasePlatform()->modifyLimitQuery($query, intval($amount), intval(($page - 1) * $amount));

        $params = array(
            $context, $minlevel
        );
        $paramTypes = array(
            DoctrineConn::PARAM_STR_ARRAY, \PDO::PARAM_INT
        );

        try {
            $stmt = $this->app['db']->executeQuery($query, $params, $paramTypes);

            $rows = $stmt->fetchAll(2); // 2 = Query::HYDRATE_COLUMN

            // Set up the pager
            $pagerQuery = sprintf(
                "SELECT count(*) as count FROM %s WHERE context IN (?) OR (level >= ?)",
                $this->tablename
            );
            $params = array($context, $minlevel);
            $paramTypes = array(DoctrineConn::PARAM_STR_ARRAY, \PDO::PARAM_INT);
            $rowcount = $this->app['db']->executeQuery($pagerQuery, $params, $paramTypes)->fetch();

            $pager = array(
                'for' => 'activity',
                'count' => $rowcount['count'],
                'totalpages' => ceil($rowcount['count'] / $amount),
                'current' => $page,
                'showing_from' => ($page - 1) * $amount + 1,
                'showing_to' => ($page - 1) * $amount + count($rows)
            );

            $this->app['storage']->setPager('activity', $pager);

        } catch (\Doctrine\DBAL\DBALException $e) {
            // Oops. User will get a warning on the dashboard about tables that need to be repaired.
            $rows = array();
        }
        
        return $rows;
    }

    /**
     * Initialize
     */
    private function initialize()
    {
        $this->tablename = sprintf("%s%s", $this->app['config']->get('general/database/prefix', "bolt_"), 'log');
        $this->initialized = true;
    }
}
