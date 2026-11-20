<?php

$router->get('/', 'Welcome::index');

// JSON endpoints for the React front end (Front-End/). Authentication uses the
// session cookie; access checks live in each controller (see SpaController).
$router->group(['prefix' => '/api/app'], function ($router) {
    // Session
    $router->get('/session', 'AuthController::session_info');
    $router->post('/login', 'AuthController::login');
    $router->post('/logout', 'AuthController::logout');


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

    // Training centers
    $router->get('/centers', 'TrainingCenterController::index');
    $router->post('/centers', 'TrainingCenterController::create');

    // Inventory
    $router->get('/products', 'ProductController::index');
    $router->post('/products', 'ProductController::create');
    $router->get('/products/{id}', 'ProductController::show');
    $router->put('/products/{id}', 'ProductController::update');
    $router->delete('/products/{id}', 'ProductController::delete');

    // Supply requests (learning center -> main office)
    $router->get('/inventory-requests', 'InventoryRequestController::index');
    $router->post('/inventory-requests', 'InventoryRequestController::create');
    $router->get('/inventory-requests/{id}', 'InventoryRequestController::show');
    $router->post('/inventory-requests/{id}/review', 'InventoryRequestController::review');
    $router->post('/inventory-requests/{id}/release', 'InventoryRequestController::release');
    $router->post('/inventory-requests/{id}/received', 'InventoryRequestController::received');
    // Allowance requests (one per batch) and the trainee checklist
    $router->get('/allowance-requests', 'AllowanceController::index');
    $router->post('/allowance-requests', 'AllowanceController::create');
    $router->get('/allowance-requests/{id}', 'AllowanceController::show');
    $router->put('/allowance-requests/{id}/students/{studentId}', 'AllowanceController::check_student');
    $router->post('/allowance-requests/{id}/status', 'AllowanceController::set_status');
    // Batches, trainees, delivery personnel
    $router->get('/batches', 'BatchController::center_batches');
    $router->get('/courses/{id}/batches', 'BatchController::index');
    $router->post('/courses/{id}/batches', 'BatchController::create');
    $router->get('/batches/{id}/students', 'BatchController::students');
    $router->get('/delivery-personnel', 'BatchController::delivery');
    $router->post('/delivery-personnel', 'BatchController::add_delivery');
    // Dashboard numbers
    $router->get('/summary', 'SummaryController::index');
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
