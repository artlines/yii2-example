<?php

namespace app\core\readModels\TaskTracks;

use app\core\entities\Staff\dto\UserMetricDto;
use app\core\entities\Staff\dto\UserMetricRatingDto;
use app\core\entities\TaskTracks\dto\WorkedTimeForUserAndYearDto;
use app\core\entities\TaskTracks\JiraTask;
use app\core\entities\TaskTracks\TaskTrackJira;
use app\core\entities\TaskTracks\dto\ProjectDto;
use app\core\entities\TaskTracks\dto\WorkedTimeForProjectDto;
use app\core\entities\TaskTracks\vo\TaskTrackSystemType;
use yii\db\Connection;
use yii\db\Query;

class JiraTaskTracksReadRepository implements TaskTracksReadRepositoryInterface
{
    private $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    private function getSelectQuery(): Query
    {
        $query = new Query();
        $query->select([
            'wl.id AS id',
            '(wl.timeworked/60) AS timeworked',
            'LOWER(ut.lower_user_name) AS user',
            'wl.STARTDATE AS date',
            'p.ID AS project_id',
            'p.pname AS project_name',
            'p.pkey AS project_key',
            'LOWER(ua.lower_user_name) AS assigneed_user',
            'i.pkey AS pkey',
            'i.id AS task_id',
            'i.SUMMARY AS task_name',
            'i.issuenum AS task_key',
            'is.pname AS status',
        ]);

        $query->from('worklog wl');

        $query->leftJoin('jiraissue i', 'i.ID = wl.issueid');
        $query->leftJoin('project p', 'p.ID = i.PROJECT');
        $query->leftJoin('issuestatus is', 'i.issuestatus = is.ID');
        $query->leftJoin('app_user AS ut', 'ut.user_key = wl.AUTHOR');
        $query->leftJoin('app_user AS ua', 'ua.user_key = i.ASSIGNEE');

        return $query;
    }

    public function findByFilter(
        \DateTimeInterface $start,
        \DateTimeInterface $end,
        ?int $project_id = null,
        ?array $task_ids = null,
        ?array $users = null,
        bool $onlyTrackedBy = false
    ): array {
        $query = $this->getSelectQuery();
        $query->andWhere(['<', 'wl.STARTDATE', $end->format('Y-m-d 23:59:59')]);
        $query->andWhere(['>=', 'wl.STARTDATE', $start->format('Y-m-d 00:00:00')]);
        $query->andFilterWhere(['p.ID' => $project_id]);

        if (is_array($task_ids)) {
            $query->andWhere(['i.id' => $task_ids]);
        }

        if ($onlyTrackedBy) {
            $query->andFilterWhere(['LOWER(ut.lower_user_name)' => $users]);
        } else {
            $query->andFilterWhere([
                'or',
                ['LOWER(ut.lower_user_name)' => $users],
                ['LOWER(ua.lower_user_name)' => $users]
            ]);
        }

        $rows = $query->all($this->db);

        $items = [];

        foreach ($rows as $row) {
            $items[] = $this->convertRowToWorkflowTrack($row);
        }

        return $items;
    }

    public function getTracksByIds(array $ids): array
    {
        $q = $this->getSelectQuery();
        $q->where(['wl.ID' => $ids]);

        $rows = $q->all($this->db);

        $items = [];

        foreach ($rows as $row) {
            $items[] = $this->convertRowToWorkflowTrack($row);
        }

        return $items;
    }

    public function findWorkedTimeForProjects(\DatePeriod $period, string $manager = null): array
    {
        $query = new Query();
        $query->select([
            'SUM(wl.timeworked) / 60 AS worked_minutes',
            'p.ID AS project_id',
            'p.pname AS project_name',
            'p.pkey AS project_key',
            'LOWER(u.lower_user_name) AS manager',
        ]);

        $query->from('worklog AS wl');

        $query->leftJoin('jiraissue AS i', 'i.ID = wl.issueid');
        $query->leftJoin('project AS p', 'p.ID = i.PROJECT');
        $query->leftJoin('app_user AS u', 'u.user_key = p.LEAD');

        $query->andWhere(['>=', 'wl.STARTDATE', $period->getStartDate()->format('Y-m-d 00:00:00')]);
        $query->andWhere(['<=', 'wl.STARTDATE', $period->getEndDate()->format('Y-m-d 23:59:59')]);
        $query->andFilterWhere(['LOWER(u.lower_user_name)' => $manager]);

        $query->groupBy('manager, project_id');

        $rows = $query->all($this->db);

        $items = [];

        foreach ($rows as $row) {
            $items[] = new WorkedTimeForProjectDto(
                new TaskTrackSystemType(TaskTrackSystemType::TYPE_JIRA),
                (int)$row['worked_minutes'],
                (int)$row['project_id'],
                $row['project_name'],
                $row['project_key'],
                $row['manager']
            );
        }

        return $items;
    }

    /**
     * @return UserMetricDto[]
     */
    public function findUsersWorkedTimeStatsForPeriod(array $users, \DatePeriod $period, bool $groupByMonths): array
    {
        $query = $this->findUsersWorkedTimeQuery($period)
            ->addSelect(
                ($groupByMonths ? 'DATE_FORMAT(wl.STARTDATE, \'%Y-%m\')' : 'DATE(wl.STARTDATE)') . ' AS date'
            )
            ->andWhere(['LOWER(u.lower_user_name)' => $users])
            ->groupBy(['date', 'login']);

        return array_map(function (array $row) {
            return new UserMetricDto($row['login'], $row['date'], (float)$row['timeworked']);
        }, $query->all($this->db));
    }

    /**
     * @return UserMetricRatingDto[]
     */
    public function findUsersWorkedTimeRatingForPeriod(\DatePeriod $period, int $limit, ?array $users = null): array
    {
        $query = $this->findUsersWorkedTimeQuery($period);

        if ($users !== null) {
            $query->andWhere(['u.lower_user_name' => $users]);
        }

        $query->orderBy('timeworked DESC')
            ->limit($limit)
            ->groupBy(['login']);

        return array_map(function (array $row) {
            return new UserMetricRatingDto($row['login'], (float)$row['timeworked']);
        }, $query->all($this->db));
    }

    private function convertRowToWorkflowTrack(array $row): TaskTrackJira
    {
        $date = \DateTime::createFromFormat('Y-m-d H:i:s', $row['date']);

        $model = TaskTrackJira::create(
            (int)$row['id'],
            $date,
            (int)$row['timeworked'],
            (string)$row['status']
        );

        if ($row['user'] !== null) {
            $model->setUser($row['user']);
        }

        if ($row['assigneed_user'] !== null) {
            $model->setAssignedUser($row['assigneed_user']);
        }

        if (!empty($row['project_id'])) {
            $model->setProject(new ProjectDto(
                new TaskTrackSystemType(TaskTrackSystemType::TYPE_JIRA),
                $row['project_id'],
                $row['project_name'],
                $row['project_key']
            ));
        }

        if ($row['task_id']) {
            $task = JiraTask::create($row['task_id'], $row['task_name'], $row['task_key']);
            $model->setTask($task);
        }

        return $model;
    }

    private function findUsersWorkedTimeQuery(\DatePeriod $period): Query
    {
        $query = new Query();

        $query->select([
            'LOWER(u.lower_user_name) AS login',
            'SUM(wl.timeworked) / 60 AS timeworked'
        ]);

        $query->from('worklog AS wl')
            ->leftJoin('app_user AS u', 'u.user_key = wl.AUTHOR')
            ->andWhere(['>=', 'wl.STARTDATE', $period->getStartDate()->format('Y-m-d 00:00:00')])
            ->andWhere(['<=', 'wl.STARTDATE', $period->getEndDate()->format('Y-m-d 23:59:59')])
            ->andWhere(['IS NOT', 'u.lower_user_name', null]);

        return $query;
    }

    public function findWorkedMinutesByIssueKeys(array $issueKeys): array
    {
        $query = new Query();
        $tracks = $query->select([
            'CONCAT(p.pkey, \'-\', i.issuenum) AS issue_key',
            'SUM(timeworked) / 60 AS worked_minutes'
        ])
            ->from('worklog AS wl')
            ->leftJoin('jiraissue AS i', 'i.ID = wl.issueid')
            ->leftJoin('project AS p', 'p.ID = i.PROJECT')
            ->where(['CONCAT(p.pkey, \'-\', i.issuenum)' => $issueKeys])
            ->groupBy('i.ID')
            ->all($this->db);

        return array_column($tracks, 'worked_minutes', 'issue_key');
    }

    /**
     * @return WorkedTimeForUserAndYearDto[]
     */
    public function getYearTrackSummaryForProjectsAndUsers(
        array $projectIds,
        array $userLogins,
        ?int $yearFrom = null
    ): array {
        $query = new Query();

        $yearExpression = 'DATE_FORMAT(wl.STARTDATE, "%Y")';

        $query->select([
            'LOWER(u.lower_user_name) AS login',
            'SUM(wl.timeworked) / 60 AS timeworked',
            $yearExpression . ' AS year'
        ]);

        $query->from('worklog AS wl')
            ->leftJoin('jiraissue i', 'i.ID = wl.issueid')
            ->leftJoin('project p', 'p.ID = i.PROJECT')
            ->leftJoin('app_user AS u', 'u.user_key = wl.AUTHOR')
            ->andWhere(['IS NOT', 'u.lower_user_name', null]);

        $query->andFilterWhere(['LOWER(u.lower_user_name)' => $userLogins]);
        $query->andFilterWhere(['p.ID' => $projectIds]);
        $query->andFilterWhere(['>=', $yearExpression, $yearFrom]);

        $query->groupBy(['u.lower_user_name', $yearExpression]);

        return array_map(function (array $row) {
            return new WorkedTimeForUserAndYearDto((float)$row['timeworked'], $row['login'], $row['year']);
        }, $query->all($this->db));
    }
}
