<?php

declare(strict_types=1);

use App\Core\Request;
use App\Core\Router;
use App\Controllers\ActionController;
use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\BuildingCodeDutyController;
use App\Controllers\CalendarController;
use App\Controllers\DashboardController;
use App\Controllers\DictionaryController;
use App\Controllers\EventController;
use App\Controllers\ExportController;
use App\Controllers\PersonController;
use App\Controllers\PlaceController;
use App\Controllers\UserController;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/Core/helpers.php';

// Error reporting: log everything (incl. mail() warnings), never show it to visitors.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../storage/logs/error.log');

// Base path: directory where index.php lives, relative to server root.
// Auto-detected so the app works in any subdirectory without configuration.
define('APP_BASE', rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'), '/'));

// Bootstrap application config
$appConfig = \App\Core\Config::load('app');
date_default_timezone_set($appConfig['timezone']);

// Start session before any output
session_start();

// Build request
$request = new Request();

// Register routes
$router = new Router();

// Auth
$router->get('/login',    [AuthController::class, 'loginPage']);
$router->post('/login',   [AuthController::class, 'loginPost']);
$router->get('/logout',   [AuthController::class, 'logout']);
$router->post('/logout',  [AuthController::class, 'logout']);
// Public self-registration disabled — new users are now created by an administrator
// (see /admin/users below). Kept commented out, not deleted, in case it's needed again.
// $router->get('/register', [AuthController::class, 'registerPage']);
// $router->post('/register',[AuthController::class, 'registerPost']);
$router->post('/role/switch/{roleId}', [AuthController::class, 'switchRole']);

// Profile
$router->get('/profile',                                   [UserController::class, 'profile']);
$router->post('/profile/contacts',                          [UserController::class, 'addContact']);
$router->post('/profile/contacts/{contactId}/deactivate',   [UserController::class, 'deactivateContact']);
$router->post('/profile/contacts/{contactId}/activate',     [UserController::class, 'activateContact']);
$router->post('/profile/email',                             [UserController::class, 'changeEmail']);
$router->post('/profile/password',                          [UserController::class, 'changePassword']);

// Dashboard
$router->get('/',  [DashboardController::class, 'index']);
$router->post('/notifications/{id}/read', [DashboardController::class, 'markNotificationRead']);
$router->post('/notifications/read-all',  [DashboardController::class, 'markAllNotificationsRead']);

// Events
$router->get('/events',             [EventController::class, 'index']);
$router->get('/events/{id}',        [EventController::class, 'show']);
$router->get('/events/{id}/edit',   [EventController::class, 'editForm']);
$router->post('/events/{id}',       [EventController::class, 'editPost']);

// People
$router->get('/people',                              [PersonController::class, 'index']);
$router->get('/people/create',                       [PersonController::class, 'createForm']);
$router->post('/people',                             [PersonController::class, 'createPost']);
$router->get('/people/{id}',                         [PersonController::class, 'show']);
$router->get('/people/{id}/edit',                    [PersonController::class, 'editForm']);
$router->post('/people/{id}',                        [PersonController::class, 'editPost']);
$router->post('/people/{id}/deactivate',             [PersonController::class, 'deactivate']);
$router->post('/people/{id}/activate',               [PersonController::class, 'activate']);
$router->post('/people/{id}/delete',                 [PersonController::class, 'delete']);
$router->post('/people/{id}/create-user',            [PersonController::class, 'createUser']);
$router->post('/people/{id}/contacts',               [PersonController::class, 'addContact']);
$router->post('/people/{id}/contacts/{contactId}/deactivate', [PersonController::class, 'deactivateContact']);
$router->post('/people/{id}/contacts/{contactId}/activate',   [PersonController::class, 'activateContact']);
$router->post('/people/{id}/contacts/{contactId}/edit',       [PersonController::class, 'editContact']);
$router->post('/people/{id}/contacts/{contactId}/delete',     [PersonController::class, 'deleteContact']);

// Actions
$router->get('/actions',                            [ActionController::class, 'index']);
$router->get('/actions/create',                     [ActionController::class, 'createForm']);
$router->post('/actions',                           [ActionController::class, 'createPost']);
$router->get('/actions/{id}',                       [ActionController::class, 'show']);
$router->get('/actions/{id}/edit',                  [ActionController::class, 'editForm']);
$router->post('/actions/{id}',                      [ActionController::class, 'editPost']);
$router->get('/actions/{id}/edit-economic',         [ActionController::class, 'editEconomicForm']);
$router->post('/actions/{id}/edit-economic',        [ActionController::class, 'editEconomicPost']);
$router->post('/actions/{id}/transition/{status}',  [ActionController::class, 'transition']);
$router->post('/actions/{id}/delete',               [ActionController::class, 'delete']);
$router->get('/actions/{id}/duplicate',             [ActionController::class, 'duplicateForm']);

// Actions: rooms (requests → confirmed assignment)
$router->post('/actions/{id}/places', [ActionController::class, 'savePlaces']);

// Actions: attendance
$router->get('/actions/{id}/edit-attendance',  [ActionController::class, 'editAttendanceForm']);
$router->post('/actions/{id}/edit-attendance', [ActionController::class, 'addAttendancePost']);
$router->post('/actions/{id}/attendance/{attendanceId}/remove', [ActionController::class, 'removeAttendance']);

// Calendar
$router->get('/calendar/places',  [CalendarController::class, 'places']);

// Building code duty
$router->get('/admin/building-code-duty',       [BuildingCodeDutyController::class, 'admin']);
$router->post('/admin/building-code-duty/save', [BuildingCodeDutyController::class, 'save']);

// Export
$router->get('/export',       [ExportController::class, 'index']);
$router->get('/export/print', [ExportController::class, 'print']);
$router->get('/export/excel', [ExportController::class, 'excel']);
$router->get('/export/statistika/print', [ExportController::class, 'statisticsPrint']);
$router->get('/export/statistika/excel', [ExportController::class, 'statisticsExcel']);
$router->get('/export/rooms/print', [ExportController::class, 'roomsPrint']);
$router->get('/export/rooms/excel', [ExportController::class, 'roomsExcel']);
$router->get('/export/media-team/print', [ExportController::class, 'mediaTeamPrint']);
$router->get('/export/media-team/excel', [ExportController::class, 'mediaTeamExcel']);
$router->get('/export/maintenance/print', [ExportController::class, 'maintenancePrint']);
$router->get('/export/maintenance/excel', [ExportController::class, 'maintenanceExcel']);
$router->get('/export/weekly/print', [ExportController::class, 'weeklyPrint']);

// Admin: users, roles & permissions
$router->get('/admin/roles',                                [AdminController::class, 'roles']);
$router->post('/admin/roles',                                [AdminController::class, 'createRole']);
$router->post('/admin/roles/{roleId}/grant/{operationId}',  [AdminController::class, 'grantOperation']);
$router->post('/admin/roles/{roleId}/revoke/{operationId}', [AdminController::class, 'revokeOperation']);
$router->post('/admin/users',                                [AdminController::class, 'createUser']);
$router->post('/admin/users/{userId}/roles',                [AdminController::class, 'assignUserRole']);
$router->post('/admin/users/{userId}/roles/{roleId}/remove',[AdminController::class, 'removeUserRole']);
$router->post('/admin/users/{userId}/delete',                [AdminController::class, 'deleteUser']);
$router->post('/admin/users/{userId}/deactivate',            [AdminController::class, 'deactivateUser']);
$router->post('/admin/users/{userId}/activate',              [AdminController::class, 'activateUser']);
$router->get('/admin/users/{userId}/edit',                   [AdminController::class, 'editUserForm']);
$router->post('/admin/users/{userId}',                       [AdminController::class, 'editUserPost']);
$router->post('/admin/users/{userId}/change-email',          [AdminController::class, 'changeUserEmail']);

// Admin: dictionaries & rooms
$router->get('/admin/dictionaries',                           [DictionaryController::class, 'index']);
$router->post('/admin/dictionaries/{type}',                   [DictionaryController::class, 'create']);
$router->post('/admin/dictionaries/{type}/{id}/update',       [DictionaryController::class, 'update']);
$router->post('/admin/dictionaries/{type}/{id}/deactivate',   [DictionaryController::class, 'deactivate']);
$router->post('/admin/dictionaries/{type}/{id}/activate',     [DictionaryController::class, 'activate']);
$router->post('/admin/currencies',                            [DictionaryController::class, 'createCurrency']);
$router->post('/admin/currencies/{id}/deactivate',            [DictionaryController::class, 'deactivateCurrency']);
$router->post('/admin/currencies/{id}/activate',              [DictionaryController::class, 'activateCurrency']);
$router->post('/admin/income-subtypes/{incomeTypeId}',        [DictionaryController::class, 'createIncomeSubtype']);
$router->post('/admin/income-subtypes/{id}/update',           [DictionaryController::class, 'updateIncomeSubtype']);
$router->post('/admin/income-subtypes/{id}/deactivate',       [DictionaryController::class, 'deactivateIncomeSubtype']);
$router->post('/admin/income-subtypes/{id}/activate',         [DictionaryController::class, 'activateIncomeSubtype']);

// Places (rooms)
$router->post('/places',                    [PlaceController::class, 'create']);
$router->get('/places/{id}/edit',           [PlaceController::class, 'editForm']);
$router->post('/places/{id}',               [PlaceController::class, 'editPost']);
$router->post('/places/{id}/deactivate',    [PlaceController::class, 'deactivate']);
$router->post('/places/{id}/activate',      [PlaceController::class, 'activate']);

// Dispatch
$router->dispatch($request);
