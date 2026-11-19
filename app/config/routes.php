<?php

$router->get('/', 'Welcome::index');

// JSON endpoints for the React front end (Front-End/). Authentication uses the
// session cookie; access checks live in each controller (see SpaController).
$router->group(['prefix' => '/api/app'], function ($router) {
    // Session and public pages
    $router->get('/session', 'AuthController::session_info');
    $router->post('/login', 'AuthController::login');
    $router->post('/logout', 'AuthController::logout');
    $router->get('/apply/centers', 'AuthController::centers');
    $router->post('/apply', 'AuthController::apply');

    $router->get('/dashboard', 'Welcome::dashboard');

    // Students
    $router->get('/student/courses', 'StudentController::courses');
    $router->get('/student/profile', 'StudentController::profile');
    $router->put('/student/profile', 'StudentController::update_profile');

    // Instructors
    $router->get('/instructor', 'InstructorController::index');
    $router->post('/instructor/enroll', 'InstructorController::enroll');
    $router->put('/instructor/courses/{id}', 'CourseController::update_delivery_details');
    $router->post('/instructor/courses/{id}/materials', 'CourseController::add_material');

    // Courses
    $router->get('/course-options', 'CourseController::form_options');
    $router->get('/courses', 'CourseController::index');
    $router->post('/courses', 'CourseController::create');
    $router->get('/courses/{id}', 'CourseController::detail');
    $router->put('/courses/{id}', 'CourseController::update');
    $router->delete('/courses/{id}', 'CourseController::delete');

    // User accounts
    $router->get('/user-options', 'UserController::form_options');
    $router->get('/users', 'UserController::index');
    $router->post('/users', 'UserController::create');
    $router->get('/users/{id}', 'UserController::show');
    $router->put('/users/{id}', 'UserController::update');
    $router->delete('/users/{id}', 'UserController::delete');

    // Training centers and applications
    $router->get('/centers', 'TrainingCenterController::index');
    $router->post('/centers', 'TrainingCenterController::create');
    $router->get('/applications', 'CenterApplicationController::index');
    $router->post('/applications/{id}/approve', 'CenterApplicationController::approve');
    $router->post('/applications/{id}/reject', 'CenterApplicationController::reject');

    // Inventory
    $router->get('/products', 'ProductController::index');
    $router->post('/products', 'ProductController::create');
    $router->get('/products/{id}', 'ProductController::show');
    $router->put('/products/{id}', 'ProductController::update');
    $router->delete('/products/{id}', 'ProductController::delete');

    // Document AI lab
    $router->post('/document-ai/analyze', 'DocumentAIController::analyze');
    $router->post('/document-ai/sample', 'DocumentAIController::sample');
    $router->post('/document-ai/text', 'DocumentAIController::classify_text');

    // Document archive
    $router->get('/documents', 'DocumentArchiveController::index');
    $router->post('/documents', 'DocumentArchiveController::upload');
    $router->get('/documents/chat', 'DocumentArchiveController::chat_history');
    $router->post('/documents/chat', 'DocumentArchiveController::chat');
    $router->delete('/documents/chat', 'DocumentArchiveController::clear_chat');
    $router->get('/documents/{id}/download', 'DocumentArchiveController::download');
});

// API authentication and product CRUD
$router->group(['prefix' => '/api'], function ($router) {
    $router->post('/login', 'ApiController::login');
    $router->post('/register', 'ApiController::register');
    $router->post('/logout', 'ApiController::logout');
    $router->post('/refresh', 'ApiController::refresh');
    $router->get('/profile', 'ApiController::profile');
    $router->get('/users', 'ApiController::list');
    $router->post('/users', 'ApiController::create');
    $router->put('/users/{id}', 'ApiController::update');
    $router->delete('/users/{id}', 'ApiController::delete');
    $router->get('/products', 'ApiController::products');
    $router->post('/products', 'ApiController::product_create');
    $router->put('/products/{id}', 'ApiController::product_update');
    $router->delete('/products/{id}', 'ApiController::product_delete');

    // Browser clients send OPTIONS before JSON requests.
    $router->options('/login', 'ApiController::login');
    $router->options('/register', 'ApiController::register');
    $router->options('/logout', 'ApiController::logout');
    $router->options('/refresh', 'ApiController::refresh');
    $router->options('/profile', 'ApiController::profile');
    $router->options('/users', 'ApiController::list');
    $router->options('/users/{id}', 'ApiController::update');
    $router->options('/products', 'ApiController::products');
    $router->options('/products/{id}', 'ApiController::product_update');
});

// lab 6 migrations
$router->get('/migration/create/{migration_class}', 'MigrationController::create_migration');
$router->get('/migration/migrate', 'MigrationController::migrate');
$router->get('/migration/rollback', 'MigrationController::rollback');
$router->get('/migration/rollback-all', 'MigrationController::rollback_all');
$router->get('/migration/refresh', 'MigrationController::refresh');
$router->get('/migration/status', 'MigrationController::status');
