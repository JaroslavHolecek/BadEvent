<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\ActionRepository;
use App\Repositories\BuildingCodeDutyRepository;
use App\Repositories\CalendarRepository;
use App\Repositories\DictionaryRepository;
use App\Repositories\EventRepository;
use App\Repositories\FinanceRepository;
use App\Repositories\PlaceRepository;
use App\Repositories\UserRepository;
use App\Services\ActionWorkflowService;
use App\Services\AuditLogService;
use App\Services\CalendarService;
use App\Services\PermissionService;

final class ActionController extends Controller
{
    private const ALL_STATUSES = ['draft', 'not_agreed', 'confirmed', 'completed', 'cancelled', 'closed'];
    private const TERMINAL_STATUSES = ['not_agreed', 'cancelled', 'closed'];

    public function index(Request $request, array $params): void
    {
        $this->requireAuth();
        $userId         = Auth::userId();
        $canViewArchive = $userId !== null && (new PermissionService())->can($userId, 'archive.view');

        $filtered  = $request->get('filtered') === '1';
        $requested = (array) $request->get('status', []);
        $statusCodes = $filtered
            ? array_values(array_intersect(self::ALL_STATUSES, $requested))
            : array_values(array_diff(self::ALL_STATUSES, self::TERMINAL_STATUSES));

        if (!$canViewArchive) {
            $statusCodes = array_values(array_diff($statusCodes, self::TERMINAL_STATUSES));
        }
        if (empty($statusCodes)) {
            $statusCodes = array_values(array_diff(self::ALL_STATUSES, self::TERMINAL_STATUSES));
        }

        $onlyMine     = $request->get('only_mine') === '1';
        $involvedUser = $onlyMine ? $userId : null;

        $placeIds = array_values(array_filter(
            array_map('intval', (array) $request->get('place_id', [])),
            static fn(int $id): bool => $id > 0
        ));

        $actionsRepo     = new ActionRepository();
        $calendarService = new CalendarService();
        [$year, $month]  = $calendarService->resolveYearMonth($request);
        $monthActions    = (new CalendarRepository())->actionsForMonth($year, $month, $statusCodes, $involvedUser, $placeIds);

        [$dateFrom, $dateTo] = $this->resolveDateRange($request);

        View::renderWithLayout('actions/index', [
            'title'          => 'Kalendář akcí',
            'actions'        => $actionsRepo->listByStatuses($statusCodes, $dateFrom, $dateTo !== null ? $dateTo . ' 23:59:59' : null, 200, 0, $involvedUser, $placeIds),
            'statuses'       => $actionsRepo->listStatuses(),
            'statusCodes'    => $statusCodes,
            'terminal'       => self::TERMINAL_STATUSES,
            'canViewArchive' => $canViewArchive,
            'onlyMine'       => $onlyMine,
            'places'         => (new PlaceRepository())->listActive(),
            'placeIds'       => $placeIds,
            'year'           => $year,
            'month'          => $month,
            'grid'           => $calendarService->buildDayGrid($year, $month, $monthActions),
            'dayNote'        => (new BuildingCodeDutyRepository())->forDate(date('Y-m-d'))['note'] ?? null,
            'dateFrom'       => $dateFrom,
            'dateTo'         => $dateTo,
            'allowedOps'     => $this->allowedOps(),
            'flash'          => $this->getFlash(),
        ]);
    }

    /**
     * Resolves the actions list's date-range filter: when neither GET param is present (first
     * visit), applies the configured default (today ± days_back/days_forward); otherwise uses
     * whatever was submitted as-is, where an empty string means "no bound in that direction".
     *
     * @return array{0: ?string, 1: ?string} [dateFrom, dateTo], both 'Y-m-d' or null
     */
    private function resolveDateRange(Request $request): array
    {
        $rawFrom = $request->get('date_from');
        $rawTo   = $request->get('date_to');

        if ($rawFrom === null && $rawTo === null) {
            $appConfig = \App\Core\Config::load('app');
            $defaults  = $appConfig['action_list_defaults'] ?? ['days_back' => 30, 'days_forward' => 30];
            $dateFrom  = date('Y-m-d', strtotime('-' . (int) $defaults['days_back'] . ' days'));
            $dateTo    = date('Y-m-d', strtotime('+' . (int) $defaults['days_forward'] . ' days'));
            return [$dateFrom, $dateTo];
        }

        return [
            $rawFrom !== '' ? (string) $rawFrom : null,
            $rawTo   !== '' ? (string) $rawTo   : null,
        ];
    }

    public function show(Request $request, array $params): void
    {
        $this->requireAuth();
        $repo   = new ActionRepository();
        $id     = (int) $params['id'];
        $action = $repo->findById($id);

        if ($action === null) {
            Response::notFound();
        }

        $finance = new FinanceRepository();
        $staffData = $this->staffData($repo, $id);
        $userId  = Auth::userId();
        $isAdmin = $userId !== null && (new PermissionService())->isAdmin($userId);

        View::renderWithLayout('actions/show', [
            'title'                => $action['name'],
            'action'               => $action,
            'isAdmin'              => $isAdmin,
            'transitionWarnings'   => $isAdmin ? $this->transitionWarnings($repo, $id, $action['status_code']) : [],
            'statusHistory'        => $repo->getStatusHistory($id),
            'organizers'           => $staffData['organizers'],
            'substitutes'          => $staffData['substitutes'],
            'staff'                => $staffData['staff'],
            'placeDesires'         => $this->placeDesiresData($repo, $id),
            'actionTypes'          => $repo->getActionTypes($id),
            'actionTopics'         => $repo->getActionTopics($id),
            'actionFormats'        => $repo->getActionFormats($id),
            'visitorCategories'    => $repo->getTargetVisitorCategories($id),
            'attendance'           => $repo->getAttendance($id),
            'incomes'              => $finance->listIncomes($id),
            'costs'                => $finance->listCosts($id),
            'incomeTotals'         => $finance->incomeTotals($id),
            'costTotals'           => $finance->costTotals($id),
            'allowedOps'           => $this->allowedOps(),
            'canEditAction'        => $this->canEditAction($repo, $action),
            'flash'                => $this->getFlash(),
        ]);
    }

    /** Returns an action's room requests, each annotated with its queue position. */
    private function placeDesiresData(ActionRepository $repo, int $id): array
    {
        return array_map(function (array $d) use ($repo, $id) {
            $placeId = (int) $d['place_id'];
            $d['queue_position'] = $repo->queuePosition($id, $placeId);
            return $d;
        }, $repo->getPlaceDesires($id));
    }

    /**
     * Returns every active room's current tri-state (none / requested / confirmed) for the edit
     * form's per-room picker, keyed by place_id: 'state', 'requested_at', 'queue_position' (null
     * for 'none') and 'note'. The "Zabraná" choice is always selectable regardless of state —
     * syncPlaceConfirmations() resolves it server-side to either an immediate confirmation or a
     * queued request, so no "can this be confirmed right now" flag needs computing here.
     */
    private function roomStatesData(ActionRepository $repo, int $id, array $places): array
    {
        $desiresByPlaceId = array_column($this->placeDesiresData($repo, $id), null, 'place_id');

        $states = [];
        foreach ($places as $p) {
            $placeId = (int) $p['id'];
            $desire  = $desiresByPlaceId[$placeId] ?? null;

            if ($desire === null) {
                $states[$placeId] = [
                    'state'          => 'none',
                    'requested_at'   => null,
                    'queue_position' => null,
                    'note'           => null,
                ];
            } else {
                $states[$placeId] = [
                    'state'          => $desire['confirmed'] ? 'confirmed' : 'requested',
                    'requested_at'   => $desire['created_at'],
                    'queue_position' => $desire['queue_position'],
                    'note'           => $desire['note'] ?? null,
                ];
            }
        }
        return $states;
    }

    /** Candidate lists for the Personál pickers — id-independent, so usable from the create form too. */
    private function staffCandidates(): array
    {
        $users = new UserRepository();
        return [
            'allOrganizers'  => $users->listByRoleCode('organizer'),
            'allSubstitutes' => $users->listByRoleCode('substitute'),
            'allStaff'       => $users->listByRoleCode('stuff'),
        ];
    }

    /** Currently-assigned organizers/substitutes/staff for an existing action, plus id sets for the checkbox pickers. */
    private function staffAssigned(ActionRepository $repo, int $id): array
    {
        $organizers  = $repo->getOrganizers($id);
        $substitutes = $repo->getSubstitutes($id);
        $staff       = $repo->getStaff($id);

        return [
            'organizers'             => $organizers,
            'substitutes'            => $substitutes,
            'staff'                  => $staff,
            'assignedOrganizerIds'   => array_map('intval', array_column($organizers, 'user_id')),
            'assignedSubstituteIds'  => array_map('intval', array_column($substitutes, 'user_id')),
            'assignedStaffPersonIds' => array_map('intval', array_column($staff, 'person_id')),
        ];
    }

    /**
     * Returns current organizers/substitutes/staff for an action, plus the full role-holder
     * lists and currently-assigned id sets (for the edit form's checkbox pickers).
     */
    private function staffData(ActionRepository $repo, int $id): array
    {
        return $this->staffAssigned($repo, $id) + $this->staffCandidates();
    }

    public function createForm(Request $request, array $params): void
    {
        $this->requirePermission('action.create');

        $events      = new EventRepository();
        $places      = new PlaceRepository();
        $dictionary  = new DictionaryRepository();

        // Coming from an event's "+ Přidat akci" link — pre-select that event.
        $preselectedEventId = (int) $request->get('event_id', 0);
        if ($preselectedEventId > 0 && $events->findById($preselectedEventId) === null) {
            $preselectedEventId = 0;
        }

        $placesList = $places->listActive();
        $userId     = Auth::userId();

        View::renderWithLayout('actions/form', [
            'title'              => 'Nová akce',
            'isEdit'             => false,
            'events'             => $events->listAvailableForAssignment(),
            'places'             => $placesList,
            'roomStates'         => $this->defaultRoomStates($placesList),
            'actionTypes'        => $dictionary->listActive('action_types'),
            'actionTopics'       => $dictionary->listActive('action_topics'),
            'actionFormats'      => $dictionary->listActive('action_formats'),
            'visitorCategories'  => $dictionary->listActive('visitor_categories'),
            'preselectedEventId' => $preselectedEventId,
            'actionDefaults'     => $this->actionDefaults(),
            'flash'              => $this->getFlash(),
            'formData'           => [],
            'organizers'             => [],
            'substitutes'            => [],
            'staff'                  => [],
            'assignedOrganizerIds'   => [$userId],
            'assignedSubstituteIds'  => [],
            'assignedStaffPersonIds' => [],
        ] + $this->staffCandidates());
    }

    /** Every active room defaulted to 'none' — used by the create form, where no action_id exists yet to check queue collisions against. */
    private function defaultRoomStates(array $places): array
    {
        $states = [];
        foreach ($places as $p) {
            $states[(int) $p['id']] = [
                'state'          => 'none',
                'requested_at'   => null,
                'queue_position' => null,
                'note'           => null,
            ];
        }
        return $states;
    }

    public function createPost(Request $request, array $params): void
    {
        $this->requirePermission('action.create');

        $data     = $request->post();
        $errors   = $this->validateActionData($data);
        if ((int) ($data['event_id'] ?? 0) === 0 && trim((string) ($data['new_event_name'] ?? '')) === '') {
            $errors['new_event_name'] = 'Vyberte existující událost, nebo vyplňte název nové události.';
        }

        if (!empty($errors)) {
            $this->failForm(implode('; ', $errors), '/actions/create', array_keys($errors));
        }

        $repo     = new ActionRepository();
        $statusId = $repo->getDraftStatusId();
        $userId   = Auth::userId();

        $this->pdo()->beginTransaction();
        try {
            $eventId = (int) $data['event_id'];

            // Auto-create event if no existing event selected and name provided
            if ($eventId === 0 && !empty($data['new_event_name'])) {
                $events  = new EventRepository();
                $eventId = $events->create(trim($data['new_event_name']));
            }

            if ($eventId === 0) {
                throw new \RuntimeException('Musíte vybrat nebo zadat událost.');
            }

            $actionId = $repo->create([
                'event_id'        => $eventId,
                'name'            => trim($data['name']),
                'staff_start_at'  => $this->parseDateTime($data['staff_start_at'] ?? ''),
                'staff_end_at'    => $this->parseDateTime($data['staff_end_at']   ?? ''),
                'public_start_at' => $this->parseDateTime($data['public_start_at'] ?? ''),
                'public_end_at'   => $this->parseDateTime($data['public_end_at']   ?? ''),
                'capacity'        => isset($data['capacity']) && $data['capacity'] !== '' ? (int) $data['capacity'] : null,
                'note'            => $data['note'] ?? null,
                'photograph'      => !empty($data['photograph']),
                'photograph_note' => trim((string) ($data['photograph_note'] ?? '')) ?: null,
                'not_main_organizer' => !empty($data['not_main_organizer']),
            ], $statusId);

            $repo->recordInitialStatus($actionId, $statusId, $userId);

            $placeStates = $this->sanitizePlaceStates((array) ($data['place_state'] ?? []));
            $placeNotes  = $this->sanitizePlaceNotes((array) ($data['place_note'] ?? []));
            $repo->syncPlaceStates($actionId, $placeStates, $placeNotes);
            (new \App\Services\PlaceQueueService())->reconcile();

            $repo->syncActionTypes($actionId, (array) ($data['action_type_id'] ?? []));
            $repo->syncActionTopics($actionId, (array) ($data['action_topic_id'] ?? []));
            $repo->syncActionFormats($actionId, (array) ($data['action_format_id'] ?? []));
            $repo->syncTargetVisitorCategories($actionId, (array) ($data['visitor_category_id'] ?? []));

            // The creating user can't be silently unassigned via this form — always force them
            // into the organizer set, on top of whatever was explicitly checked.
            $organizerIds  = array_map('intval', (array) ($data['organizer_id']  ?? []));
            $substituteIds = array_map('intval', (array) ($data['substitute_id'] ?? []));
            $staffIds      = array_map('intval', (array) ($data['staff_id']      ?? []));
            $organizerIds[] = $userId;
            $repo->syncOrganizers($actionId, array_values(array_unique($organizerIds)));
            $repo->syncSubstitutes($actionId, $substituteIds);
            $repo->syncStaff($actionId, $staffIds);

            $this->pdo()->commit();
        } catch (\Throwable $e) {
            $this->pdo()->rollBack();
            $this->failForm('Chyba při ukládání: ' . $e->getMessage(), '/actions/create');
        }

        (new AuditLogService())->log($userId, 'action', $actionId, 'create', null, ['name' => trim($data['name']), 'event_id' => $eventId]);

        $this->flashSuccess('Akce byla vytvořena.');
        Response::redirect('/actions/' . $actionId);
    }

    public function editForm(Request $request, array $params): void
    {
        $this->requireAuth();
        $repo   = new ActionRepository();
        $id     = (int) $params['id'];
        $action = $repo->findById($id);

        if ($action === null) {
            Response::notFound();
        }
        $this->requireCanEditAction($repo, $action);

        $places        = new PlaceRepository();
        $events        = new EventRepository();
        $dictionary    = new DictionaryRepository();
        $placesList    = $places->listActive();
        $staffData     = $this->staffData($repo, $id);

        $assignedActionTypes       = $repo->getActionTypeIds($id);
        $assignedActionTopics      = $repo->getActionTopicIds($id);
        $assignedActionFormats     = $repo->getActionFormatIds($id);
        $assignedVisitorCategories = $repo->getTargetVisitorCategoryIds($id);

        View::renderWithLayout('actions/form', [
            'title'                     => 'Upravit: ' . $action['name'],
            'isEdit'                    => true,
            'action'                    => $action,
            'formData'                  => [
                'name'                => $action['name'],
                'staff_start_at'      => $action['staff_start_at'] ? date('Y-m-d\TH:i', strtotime($action['staff_start_at'])) : '',
                'staff_end_at'        => $action['staff_end_at']   ? date('Y-m-d\TH:i', strtotime($action['staff_end_at'])) : '',
                'public_start_at'     => $action['public_start_at'] ? date('Y-m-d\TH:i', strtotime($action['public_start_at'])) : '',
                'public_end_at'       => $action['public_end_at']  ? date('Y-m-d\TH:i', strtotime($action['public_end_at'])) : '',
                'capacity'            => $action['capacity'] ?? '',
                'note'                => $action['note'] ?? '',
                'photograph'          => $action['photograph'],
                'photograph_note'     => $action['photograph_note'] ?? '',
                'not_main_organizer'  => $action['not_main_organizer'],
                'action_type_id'      => $assignedActionTypes,
                'action_topic_id'     => $assignedActionTopics,
                'action_format_id'    => $assignedActionFormats,
                'visitor_category_id' => $assignedVisitorCategories,
            ],
            'events'                    => $events->listAvailableForAssignment((int) $action['event_id']),
            'places'                    => $placesList,
            'roomStates'                => $this->roomStatesData($repo, $id, $placesList),
            'actionTypes'               => $dictionary->listActive('action_types'),
            'actionTopics'              => $dictionary->listActive('action_topics'),
            'actionFormats'             => $dictionary->listActive('action_formats'),
            'visitorCategories'         => $dictionary->listActive('visitor_categories'),
            'assignedActionTypes'       => $assignedActionTypes,
            'assignedActionTopics'      => $assignedActionTopics,
            'assignedActionFormats'     => $assignedActionFormats,
            'assignedVisitorCategories' => $assignedVisitorCategories,
            'actionDefaults'            => $this->actionDefaults(),
            'canDeleteAction'           => $this->canDeleteAction($repo, $action),
            'flash'                     => $this->getFlash(),
        ] + $staffData);
    }

    /** Admin can always delete; an action's organizer can delete it only while it's still draft/not_agreed/cancelled. */
    private function canDeleteAction(ActionRepository $repo, array $action): bool
    {
        $userId = Auth::userId();
        if ($userId === null) {
            return false;
        }
        if ((new PermissionService())->can($userId, 'action.delete')) {
            return true;
        }
        return $repo->isOrganizer((int) $action['id'], $userId)
            && in_array($action['status_code'], ['draft', 'not_agreed', 'cancelled'], true);
    }

    /**
     * Whether the current user may edit this action's basic info/rooms/attendance:
     * `action.update_basic` grants it for any action (administrator always has this — see
     * database/seed.sql's blanket CROSS JOIN grant), while the narrower
     * `action.own_action.update_basic` (held by the 'organizer' role by default) only grants it
     * for actions this user actually organizes. A closed action is frozen for everyone except an
     * administrator, regardless of which of those two operations they hold.
     */
    private function canEditAction(ActionRepository $repo, array $action): bool
    {
        $userId = Auth::userId();
        if ($userId === null) {
            return false;
        }

        $perm = new PermissionService();
        if ($perm->isAdmin($userId)) {
            return true;
        }
        if ($action['status_code'] === 'closed') {
            return false;
        }
        if ($perm->can($userId, 'action.update_basic')) {
            return true;
        }
        if ($perm->can($userId, 'action.own_action.update_basic')) {
            return $repo->isOrganizer((int) $action['id'], $userId);
        }
        return false;
    }

    /**
     * For an admin viewing the action page: missing-required-data warning text per reachable
     * target status code, e.g. ['confirmed' => 'Akce nemá žádnou zabranou místnost.']. Empty
     * string / absent key means that transition has no missing data. Used to power the JS
     * confirm() popup that lets an admin push a transition through anyway.
     */
    private function transitionWarnings(ActionRepository $repo, int $id, string $currentCode): array
    {
        $workflow = new ActionWorkflowService();
        $warnings = [];
        foreach ($workflow->allowedTransitions($currentCode) as $targetCode) {
            $missing = $workflow->missingRequirements($id, $currentCode, $targetCode);
            if (!empty($missing)) {
                $warnings[$targetCode] = implode(' ', $missing);
            }
        }
        return $warnings;
    }

    /** Guards editForm/editPost/savePlaces/attendance endpoints with canEditAction(), redirecting back to the action's detail page with a flash error otherwise. */
    private function requireCanEditAction(ActionRepository $repo, array $action): void
    {
        if (!$this->canEditAction($repo, $action)) {
            $this->flashError('Upravovat informace o akci a docházku může pouze organizátor této akce nebo administrátor.');
            Response::redirect('/actions/' . (int) $action['id']);
        }
    }

    /** Loaded from .envConfig/app.php; used by the create/edit views for JS-only datetime auto-fill. */
    private function actionDefaults(): array
    {
        $appConfig = \App\Core\Config::load('app');
        return $appConfig['action_defaults'];
    }

    public function editPost(Request $request, array $params): void
    {
        $this->requireAuth();
        $id   = (int) $params['id'];
        $data = $request->post();

        $repo   = new ActionRepository();
        $events = new EventRepository();
        $before = $repo->findById($id);

        if ($before === null) {
            Response::notFound();
        }
        $this->requireCanEditAction($repo, $before);

        $errors = $this->validateActionData($data);

        if (!empty($errors)) {
            $this->failForm(implode('; ', $errors), '/actions/' . $id . '/edit', array_keys($errors));
        }

        $oldEventId = (int) $before['event_id'];
        $newEventId = (int) ($data['event_id'] ?? $oldEventId);

        if ($events->findById($newEventId) === null) {
            $this->failForm('Vybraná událost neexistuje.', '/actions/' . $id . '/edit', ['event_id']);
        }

        $update = [
            'name'            => trim($data['name']),
            'event_id'        => $newEventId,
            'staff_start_at'  => $this->parseDateTime($data['staff_start_at'] ?? ''),
            'staff_end_at'    => $this->parseDateTime($data['staff_end_at']   ?? ''),
            'public_start_at' => $this->parseDateTime($data['public_start_at'] ?? ''),
            'public_end_at'   => $this->parseDateTime($data['public_end_at']   ?? ''),
            'capacity'        => isset($data['capacity']) && $data['capacity'] !== '' ? (int) $data['capacity'] : null,
            'note'            => $data['note'] ?? null,
            'photograph'      => !empty($data['photograph']),
            'photograph_note' => trim((string) ($data['photograph_note'] ?? '')) ?: null,
            'not_main_organizer' => !empty($data['not_main_organizer']),
        ];
        $placeStates        = $this->sanitizePlaceStates((array) ($data['place_state'] ?? []));
        $placeNotes         = $this->sanitizePlaceNotes((array) ($data['place_note'] ?? []));
        $actionTypeIds      = array_map('intval', (array) ($data['action_type_id'] ?? []));
        $actionTopicIds     = array_map('intval', (array) ($data['action_topic_id'] ?? []));
        $actionFormatIds    = array_map('intval', (array) ($data['action_format_id'] ?? []));
        $visitorCategoryIds = array_map('intval', (array) ($data['visitor_category_id'] ?? []));
        $organizerIds       = array_map('intval', (array) ($data['organizer_id'] ?? []));
        $substituteIds      = array_map('intval', (array) ($data['substitute_id'] ?? []));
        $staffIds           = array_map('intval', (array) ($data['staff_id'] ?? []));

        $this->pdo()->beginTransaction();
        try {
            $repo->update($id, $update);
            $repo->syncPlaceStates($id, $placeStates, $placeNotes);
            (new \App\Services\PlaceQueueService())->reconcile();
            $repo->syncActionTypes($id, $actionTypeIds);
            $repo->syncActionTopics($id, $actionTopicIds);
            $repo->syncActionFormats($id, $actionFormatIds);
            $repo->syncTargetVisitorCategories($id, $visitorCategoryIds);
            $repo->syncOrganizers($id, $organizerIds);
            $repo->syncSubstitutes($id, $substituteIds);
            $repo->syncStaff($id, $staffIds);

            // An event can't exist without an action — if this move emptied its old event, remove it too.
            if ($newEventId !== $oldEventId && $events->countActions($oldEventId) === 0) {
                $events->delete($oldEventId);
            }

            $this->pdo()->commit();
        } catch (\Throwable $e) {
            $this->pdo()->rollBack();
            $this->failForm('Chyba při ukládání: ' . $e->getMessage(), '/actions/' . $id . '/edit');
        }

        (new AuditLogService())->log(
            Auth::userId(),
            'action',
            $id,
            'update',
            $before !== null ? ['name' => $before['name'], 'event_id' => $oldEventId] : null,
            $update + ['place_states' => $placeStates]
        );

        $this->flashSuccess('Akce byla uložena.');
        Response::redirect('/actions/' . $id);
    }

    /**
     * Saves just the room requests/confirmations from the edit form's own "Uložit místnosti"
     * button, then redirects back to the edit page (unlike editPost, which returns to the
     * detail page) so the user stays on the same view after the refresh.
     */
    public function savePlaces(Request $request, array $params): void
    {
        $this->requireAuth();
        $id   = (int) $params['id'];
        $data = $request->post();

        $repo   = new ActionRepository();
        $action = $repo->findById($id);
        if ($action === null) {
            Response::notFound();
        }
        $this->requireCanEditAction($repo, $action);

        $placeStates = $this->sanitizePlaceStates((array) ($data['place_state'] ?? []));
        $placeNotes  = $this->sanitizePlaceNotes((array) ($data['place_note'] ?? []));

        $this->pdo()->beginTransaction();
        try {
            $repo->syncPlaceStates($id, $placeStates, $placeNotes);
            (new \App\Services\PlaceQueueService())->reconcile();
            $this->pdo()->commit();
        } catch (\Throwable $e) {
            $this->pdo()->rollBack();
            $this->failForm('Chyba při ukládání místností: ' . $e->getMessage(), '/actions/' . $id . '/edit', [], ['action-form-places']);
        }

        (new AuditLogService())->log(
            Auth::userId(),
            'action',
            $id,
            'update_places',
            null,
            ['place_states' => $placeStates]
        );

        $this->flashSuccess('Místnosti byly uloženy.');
        Response::redirect('/actions/' . $id . '/edit');
    }

    public function editEconomicForm(Request $request, array $params): void
    {
        $this->requirePermission('action.update_economic');
        $id     = (int) $params['id'];
        $repo   = new ActionRepository();
        $action = $repo->findById($id);

        if ($action === null) {
            Response::notFound();
        }

        $finance = new FinanceRepository();

        View::renderWithLayout('actions/edit-economic', [
            'title'          => 'Ekonomické údaje: ' . $action['name'],
            'action'         => $action,
            'incomes'        => $finance->listIncomes($id),
            'costs'          => $finance->listCosts($id),
            'incomeTypes'    => $finance->incomeTypes(),
            'incomeSubtypes' => $finance->incomeSubtypes(),
            'costTypes'      => $finance->costTypes(),
            'costObjects'    => $finance->costObjects(),
            'currencies'     => $finance->currencies(),
            'flash'          => $this->getFlash(),
        ]);
    }

    public function editEconomicPost(Request $request, array $params): void
    {
        $this->requirePermission('action.update_economic');
        $id   = (int) $params['id'];
        $data = $request->post();

        $invalidField = null;
        try {
            $incomeRows = $this->parseFinanceRows($data, 'income', true, false, true, $invalidField);
            $costRows   = $this->parseFinanceRows($data, 'cost', false, true, true, $invalidField);
        } catch (\RuntimeException $e) {
            $this->failForm($e->getMessage(), '/actions/' . $id . '/edit-economic', $invalidField !== null ? [$invalidField] : []);
        }

        $finance = new FinanceRepository();
        $pdo     = $this->pdo();
        $pdo->beginTransaction();
        try {
            $finance->replaceIncomes($id, $incomeRows);
            $finance->replaceCosts($id, $costRows);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $this->failForm('Chyba při ukládání: ' . $e->getMessage(), '/actions/' . $id . '/edit-economic');
        }

        (new AuditLogService())->log(Auth::userId(), 'action', $id, 'update_economic', null, [
            'incomes' => $incomeRows,
            'costs'   => $costRows,
        ]);

        $this->flashSuccess('Ekonomické údaje byly uloženy.');
        Response::redirect('/actions/' . $id);
    }

    /**
     * Parses parallel {prefix}_type_id[]/{prefix}_amount[]/... POST arrays into row data, skipping blank rows left by the "add row" UI.
     * On an invalid row throws, with $invalidField set to the offending input, e.g. "income_amount[2]" (the 3rd row's amount).
     */
    private function parseFinanceRows(array $data, string $prefix, bool $hasSubtype, bool $hasObject = false, bool $hasReference = false, ?string &$invalidField = null): array
    {
        $rows    = [];
        $typeKey = $prefix . '_type_id';
        $count   = isset($data[$typeKey]) ? count((array) $data[$typeKey]) : 0;

        for ($i = 0; $i < $count; $i++) {
            $typeId = (int) ($data[$typeKey][$i] ?? 0);
            $amount = trim((string) ($data[$prefix . '_amount'][$i] ?? ''));

            if ($typeId <= 0 && $amount === '') {
                continue;
            }
            if ($typeId <= 0 || $amount === '' || !is_numeric($amount)) {
                $invalidField = ($typeId <= 0 ? $typeKey : $prefix . '_amount') . "[{$i}]";
                throw new \RuntimeException('Neplatný řádek u položky č. ' . ($i + 1) . '.');
            }

            $currencyId = (int) ($data[$prefix . '_currency_id'][$i] ?? 0);
            if ($currencyId <= 0) {
                $invalidField = $prefix . "_currency_id[{$i}]";
                throw new \RuntimeException('Chybí měna u položky č. ' . ($i + 1) . '.');
            }

            $row = [
                'type_id'     => $typeId,
                'currency_id' => $currencyId,
                'amount'      => $amount,
                'note'        => trim((string) ($data[$prefix . '_note'][$i] ?? '')) ?: null,
            ];

            if ($hasSubtype) {
                $subtypeId          = (int) ($data[$prefix . '_subtype_id'][$i] ?? 0);
                $row['subtype_id']  = $subtypeId > 0 ? $subtypeId : null;
            }

            if ($hasObject) {
                $objectId = (int) ($data[$prefix . '_object_id'][$i] ?? 0);
                if ($objectId <= 0) {
                    $invalidField = $prefix . "_object_id[{$i}]";
                    throw new \RuntimeException('Chybí „za co“ u položky č. ' . ($i + 1) . '.');
                }
                $row['object_id'] = $objectId;
            }

            if ($hasReference) {
                $row['reference_number'] = trim((string) ($data[$prefix . '_reference'][$i] ?? '')) ?: null;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    public function transition(Request $request, array $params): void
    {
        $this->requireAuth();
        $id         = (int) $params['id'];
        $targetCode = (string) $params['status'];
        $userId     = Auth::userId();

        $action = (new ActionRepository())->findById($id);
        if ($action === null) {
            Response::notFound();
        }

        $opMap = [
            'confirmed'  => 'action.confirm',
            'not_agreed' => 'action.mark_not_agreed',
            'completed'  => 'action.complete',
            'cancelled'  => 'action.cancel',
            'closed'     => 'action.close',
        ];

        // Reverting a status (e.g. confirmed → draft) requires the same permission that was
        // needed to reach the current status in the first place — the current status's op.
        $workflow    = new ActionWorkflowService();
        $currentCode = $action['status_code'];
        $opKey       = $workflow->isReverseTransition($currentCode, $targetCode) ? $currentCode : $targetCode;

        $requiredOp = $opMap[$opKey] ?? null;
        if ($requiredOp !== null) {
            $perm = new \App\Services\PermissionService();
            if (!$perm->can($userId, $requiredOp)) {
                $this->flashError('Nemáte oprávnění k této změně stavu.');
                Response::redirect('/actions/' . $id);
            }
        }

        // Only an administrator may push a transition through despite missing required data
        // (confirmed via a JS warning popup on the button — see actions/show.php) — the "force"
        // POST field itself grants nothing without this permission check.
        $isAdmin = (new PermissionService())->isAdmin($userId);
        $force   = $isAdmin && $request->post('force') === '1';

        try {
            $note = $request->post('note');
            $workflow->transition($id, $targetCode, $userId, $note, $force);
            $this->flashSuccess('Stav akce byl změněn.');
        } catch (\Throwable $e) {
            // Missing required data → point at the detail page section where it's filled in.
            $sections = ['confirmed' => 'action-places', 'completed' => 'action-attendance', 'closed' => 'action-economic'];
            $missing  = !$force ? $workflow->missingRequirements($id, $currentCode, $targetCode) : [];
            $this->failForm('Chyba: ' . $e->getMessage(), '/actions/' . $id, [], $missing && isset($sections[$targetCode]) ? [$sections[$targetCode]] : []);
        }

        Response::redirect('/actions/' . $id);
    }

    public function editAttendanceForm(Request $request, array $params): void
    {
        $this->requireAuth();
        $id     = (int) $params['id'];
        $repo   = new ActionRepository();
        $action = $repo->findById($id);

        if ($action === null) {
            Response::notFound();
        }
        $this->requireCanEditAction($repo, $action);

        $dictionary = new DictionaryRepository();

        View::renderWithLayout('actions/edit-attendance', [
            'title'             => 'Docházka: ' . $action['name'],
            'action'            => $action,
            'attendance'        => $repo->getAttendance($id),
            'visitorTypes'      => $dictionary->listActive('visitor_types'),
            'visitorCategories' => $dictionary->listActive('visitor_categories'),
            'flash'             => $this->getFlash(),
        ]);
    }

    public function addAttendancePost(Request $request, array $params): void
    {
        $this->requireAuth();
        $id   = (int) $params['id'];
        $data = $request->post();

        $repo   = new ActionRepository();
        $action = $repo->findById($id);
        if ($action === null) {
            Response::notFound();
        }
        $this->requireCanEditAction($repo, $action);

        $typeId     = (int) ($data['attendance_visitor_type_id'] ?? 0);
        $categoryId = (int) ($data['attendance_visitor_category_id'] ?? 0);
        $countRaw   = trim((string) ($data['attendance_visitor_count'] ?? ''));

        $invalid = array_keys(array_filter([
            'attendance_visitor_type_id'     => $typeId <= 0,
            'attendance_visitor_category_id' => $categoryId <= 0,
            'attendance_visitor_count'       => $countRaw === '' || !ctype_digit($countRaw),
        ]));
        if ($invalid) {
            $this->failForm('Pro přidání docházky vyplňte typ, kategorii návštěvníků i nezáporný počet.', '/actions/' . $id . '/edit-attendance', $invalid);
        }

        $repo->addAttendance($id, $typeId, $categoryId, (int) $countRaw, trim((string) ($data['attendance_note'] ?? '')) ?: null);
        (new AuditLogService())->log(Auth::userId(), 'action', $id, 'add_attendance', null, [
            'visitor_type_id'     => $typeId,
            'visitor_category_id' => $categoryId,
            'visitor_count'       => (int) $countRaw,
        ]);

        $this->flashSuccess('Docházka byla zaznamenána.');
        Response::redirect('/actions/' . $id . '/edit-attendance');
    }

    public function removeAttendance(Request $request, array $params): void
    {
        $this->requireAuth();
        $id            = (int) $params['id'];
        $attendanceId  = (int) $params['attendanceId'];

        $repo   = new ActionRepository();
        $action = $repo->findById($id);
        if ($action === null) {
            Response::notFound();
        }
        $this->requireCanEditAction($repo, $action);

        $repo->removeAttendance($id, $attendanceId);
        (new AuditLogService())->log(Auth::userId(), 'action', $id, 'remove_attendance', ['attendance_id' => $attendanceId], null);

        $this->flashSuccess('Záznam docházky byl odebrán.');
        Response::redirect('/actions/' . $id . '/edit-attendance');
    }

    /** @return array<string, string> field name => error message */
    private function validateActionData(array $data): array
    {
        $errors = [];
        if (empty(trim($data['name'] ?? ''))) {
            $errors['name'] = 'Název akce je povinný.';
        }

        $dateFields = [
            'staff_start_at'  => 'Blokace pro personál od',
            'staff_end_at'    => 'Blokace pro personál do',
            'public_start_at' => 'Termín pro veřejnost od',
            'public_end_at'   => 'Termín pro veřejnost do',
        ];
        foreach ($dateFields as $field => $label) {
            $parsed = $this->parseDateTime((string) ($data[$field] ?? ''));
            if ($parsed !== null) {
                $year = (int) substr($parsed, 0, 4);
                if ($year < 2000 || $year > 2100) {
                    $errors[$field] = "Datum „{$label}“ má nesmyslný rok ({$year}) — zkontrolujte zadanou hodnotu.";
                }
            }
        }

        return $errors;
    }

    private function parseDateTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        // HTML datetime-local format: 2024-06-15T14:30
        $dt = \DateTime::createFromFormat('Y-m-d\TH:i', $value);
        return $dt ? $dt->format('Y-m-d H:i:s') : null;
    }

    private function pdo(): \PDO
    {
        return \App\Core\Database::connection();
    }

    /** @return array<int, string> place_id => sanitized 'none'|'requested'|'confirmed' (unrecognized values fall back to 'none') */
    private function sanitizePlaceStates(array $raw): array
    {
        $states = [];
        foreach ($raw as $placeId => $state) {
            $states[(int) $placeId] = in_array($state, ['none', 'requested', 'confirmed'], true) ? $state : 'none';
        }
        return $states;
    }

    /** @return array<int, string|null> place_id => trimmed note, or null when left empty */
    private function sanitizePlaceNotes(array $raw): array
    {
        $notes = [];
        foreach ($raw as $placeId => $note) {
            $note = trim((string) $note);
            $notes[(int) $placeId] = $note !== '' ? $note : null;
        }
        return $notes;
    }

    public function delete(Request $request, array $params): void
    {
        $this->requireAuth();
        $id     = (int) $params['id'];
        $repo   = new ActionRepository();
        $action = $repo->findById($id);

        if ($action === null) {
            Response::notFound();
        }

        if (!$this->canDeleteAction($repo, $action)) {
            $this->flashError('Nemáte oprávnění smazat tuto akci.');
            Response::redirect('/actions/' . $id . '/edit');
        }

        $repo->delete($id);
        (new AuditLogService())->log(Auth::userId(), 'action', $id, 'delete', ['name' => $action['name']], null);

        $this->flashSuccess('Akce byla smazána.');
        Response::redirect('/actions');
    }

    /**
     * Opens the "Nová akce" form pre-filled from an existing action, instead of saving anything
     * right away — nothing is persisted until the user actually submits "Vytvořit akci". Date/time
     * fields carry over as-is (the user is expected to adjust them for the new occurrence); economic
     * data and attendance are left out since the create form never had fields for them either.
     * Rooms carry over only as fresh 'requested' rooms, deliberately never
     * pre-selected as 'confirmed' — even though the create form's room picker can confirm a room
     * immediately on submit (via syncPlaceStates, same as the edit form), duplication shouldn't
     * silently hand the new action someone else's confirmed slot. The organizer of the new action
     * ends up being whoever submits the form — normally the same person who duplicated it, since
     * createPost() always adds the submitting user as organizer.
     */
    public function duplicateForm(Request $request, array $params): void
    {
        $this->requirePermission('action.create');
        $id     = (int) $params['id'];
        $repo   = new ActionRepository();
        $source = $repo->findById($id);

        if ($source === null) {
            Response::notFound();
        }

        $events     = new EventRepository();
        $places     = new PlaceRepository();
        $dictionary = new DictionaryRepository();
        $placesList = $places->listActive();
        $userId     = Auth::userId();

        // Rooms carry over only as fresh requests, never as confirmed assignments (see class doc above).
        $roomStates = $this->defaultRoomStates($placesList);
        foreach ($repo->getPlaceDesires($id) as $d) {
            $roomStates[(int) $d['place_id']]['state'] = 'requested';
        }

        View::renderWithLayout('actions/form', [
            'title'              => 'Nová akce',
            'isEdit'             => false,
            'events'             => $events->listAvailableForAssignment((int) $source['event_id']),
            'places'             => $placesList,
            'roomStates'         => $roomStates,
            'actionTypes'        => $dictionary->listActive('action_types'),
            'actionTopics'       => $dictionary->listActive('action_topics'),
            'actionFormats'      => $dictionary->listActive('action_formats'),
            'visitorCategories'  => $dictionary->listActive('visitor_categories'),
            'preselectedEventId' => (int) $source['event_id'],
            'actionDefaults'     => $this->actionDefaults(),
            'duplicatedFrom'     => $source['name'],
            'flash'              => $this->getFlash(),
            'organizers'             => [],
            'substitutes'            => [],
            'staff'                  => [],
            // The duplicator becomes the new action's organizer, not the source action's — matching
            // createPost()'s own "whoever submits the form" convention (see class doc above).
            'assignedOrganizerIds'   => [$userId],
            'assignedSubstituteIds'  => [],
            'assignedStaffPersonIds' => [],
            'formData'           => [
                'name'                => $source['name'],
                'staff_start_at'      => $source['staff_start_at']  ? date('Y-m-d\TH:i', strtotime($source['staff_start_at']))  : '',
                'staff_end_at'        => $source['staff_end_at']    ? date('Y-m-d\TH:i', strtotime($source['staff_end_at']))    : '',
                'public_start_at'     => $source['public_start_at'] ? date('Y-m-d\TH:i', strtotime($source['public_start_at'])) : '',
                'public_end_at'       => $source['public_end_at']   ? date('Y-m-d\TH:i', strtotime($source['public_end_at']))   : '',
                'capacity'            => $source['capacity'],
                'note'                => $source['note'],
                'photograph'          => $source['photograph'],
                'photograph_note'     => $source['photograph_note'],
                'not_main_organizer'  => $source['not_main_organizer'],
                'action_type_id'      => $repo->getActionTypeIds($id),
                'action_topic_id'     => $repo->getActionTopicIds($id),
                'action_format_id'    => $repo->getActionFormatIds($id),
                'visitor_category_id' => $repo->getTargetVisitorCategoryIds($id),
            ],
        ] + $this->staffCandidates());
    }
}
