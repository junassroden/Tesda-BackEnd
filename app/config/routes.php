<?php

$router->get('/', 'Welcome::index');

// JSON endpoints for the React front end (Front-End/). Authentication uses the
// session cookie; access checks live in each controller (see app/kernel/MY_Controller.php).
$router->group(['prefix' => '/api/app'], function ($router) {
    // Session
    $router->get('/session', 'AuthController::session_info');
    $router->post('/login', 'AuthController::login');
    $router->post('/logout', 'AuthController::logout');


    // Students
    $router->get('/student/courses', 'StudentController::courses');
    $router->get('/student/training', 'StudentController::training');
    $router->get('/student/profile', 'StudentController::profile');
    $router->put('/student/profile', 'StudentController::update_profile');
    $router->get('/student/documents', 'StudentDocumentController::mine');
    $router->post('/student/documents/{typeId}', 'StudentDocumentController::upload_mine');

    // Instructors
    $router->get('/instructor', 'InstructorController::index');
    $router->get('/instructor/batches', 'InstructorController::batches');
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
    $router->get('/products/{id}/image', 'ProductController::image');
    $router->post('/products/{id}/image', 'ProductController::upload_image');
    $router->delete('/products/{id}/image', 'ProductController::delete_image');

    // Inventory requests (learning center -> main office approval -> inventory personnel)
    $router->get('/inventory-requests', 'InventoryRequestController::index');
    $router->post('/inventory-requests', 'InventoryRequestController::create');
    $router->get('/inventory-requests/{id}', 'InventoryRequestController::show');
    $router->put('/inventory-requests/{id}', 'InventoryRequestController::update');
    $router->delete('/inventory-requests/{id}', 'InventoryRequestController::delete');
    $router->post('/inventory-requests/{id}/files', 'InventoryRequestController::upload');
    $router->delete('/inventory-requests/{id}/files/{fileId}', 'InventoryRequestController::remove_file');
    $router->post('/inventory-requests/{id}/submit', 'InventoryRequestController::submit');
    $router->post('/inventory-requests/{id}/review', 'InventoryRequestController::review');
    $router->post('/inventory-requests/{id}/processing', 'InventoryRequestController::start_processing');
    $router->post('/inventory-requests/{id}/qr', 'InventoryRequestController::generate_qr');
    $router->post('/inventory-requests/{id}/receive', 'InventoryRequestController::receive');
    $router->post('/inventory-requests/{id}/resolve', 'InventoryRequestController::resolve');
    $router->get('/inventory-request-files/{id}', 'InventoryRequestController::file');
    // Delivery Management (delivery personnel; stock is deducted only on release)
    $router->get('/deliveries', 'DeliveryController::index');
    $router->post('/deliveries/scan', 'DeliveryController::scan');
    $router->get('/deliveries/{id}', 'DeliveryController::show');
    $router->post('/deliveries/{id}/release', 'DeliveryController::release');
    $router->post('/deliveries/{id}/delivered', 'DeliveryController::delivered');
    // Main office staff accounts and everyone's notifications
    $router->get('/staff', 'StaffController::index');
    $router->post('/staff', 'StaffController::create');
    $router->put('/staff/{id}/active', 'StaffController::set_active');
    // Blockchain ledger (main office): browse and verify
    $router->get('/ledger', 'LedgerController::index');
    $router->post('/ledger/verify', 'LedgerController::verify_chain');
    $router->get('/ledger/anchors', 'LedgerController::anchors');
    $router->post('/ledger/anchors', 'LedgerController::anchor');
    $router->post('/ledger/anchors/check', 'LedgerController::check_anchors');
    $router->post('/ledger/anchors/{id}/verify', 'LedgerController::verify_anchor');
    // QR-verifiable printed documents: staff issue seals; anyone can verify one
    $router->post('/seals', 'SealController::issue');
    $router->get('/public/verify/{token}', 'SealController::verify');
    $router->get('/ledger/{id}', 'LedgerController::show');
    $router->post('/ledger/{id}/verify', 'LedgerController::verify_block');
    $router->get('/notifications', 'NotificationController::index');
    $router->post('/notifications/read', 'NotificationController::read');
    // Allowance requests (one per batch) and the trainee checklist
    $router->get('/allowance-requests', 'AllowanceController::index');
    $router->post('/allowance-requests', 'AllowanceController::create');
    $router->get('/allowance-requests/{id}', 'AllowanceController::show');
    $router->put('/allowance-requests/{id}/students/{studentId}', 'AllowanceController::check_student');
    $router->post('/allowance-requests/{id}/status', 'AllowanceController::set_status');
    // Batches: schedule, lifecycle, trainees, attendance, history
    $router->get('/batches', 'BatchController::center_batches');
    $router->get('/batches/history', 'BatchController::history');
    $router->get('/courses/{id}/batches', 'BatchController::index');
    $router->post('/courses/{id}/batches', 'BatchController::create');
    $router->get('/batches/{id}', 'BatchController::show');
    $router->put('/batches/{id}', 'BatchController::update');
    $router->put('/batches/{id}/instructor', 'BatchController::assign_instructor');
    $router->post('/batches/{id}/status', 'BatchController::change_status');
    $router->get('/batches/{id}/students', 'BatchController::students');
    $router->post('/batches/{id}/students', 'BatchController::enroll');
    $router->post('/batches/{id}/students/{studentId}/remove', 'BatchController::remove_student');
    $router->post('/batches/{id}/students/{studentId}/transfer', 'BatchController::transfer_student');
    $router->post('/batches/{id}/sessions', 'BatchController::record_session');
    $router->delete('/batches/{id}/sessions/{sessionId}', 'BatchController::delete_session');
    $router->get('/center/instructors', 'BatchController::instructors');

    // Student records and enrollment documents (learning center)
    $router->get('/center/students', 'StudentRecordController::index');
    $router->post('/center/students', 'StudentRecordController::create');
    $router->get('/center/students/{id}', 'StudentRecordController::show');
    $router->put('/center/students/{id}', 'StudentRecordController::update');
    $router->post('/center/students/{id}/documents/{typeId}', 'StudentDocumentController::upload_for');
    $router->post('/student-documents/{id}/review', 'StudentDocumentController::review');
    $router->get('/student-document-files/{id}', 'StudentDocumentController::file');
    $router->delete('/student-document-files/{id}', 'StudentDocumentController::remove_file');

    // Virtual classroom (one per batch; teachers and students)
    $router->get('/classrooms', 'ClassroomController::index');
    $router->get('/classrooms/{id}', 'ClassroomController::show');
    $router->post('/classrooms/{id}/announcements', 'ClassroomController::announce');
    $router->delete('/classrooms/{id}/announcements/{announcementId}', 'ClassroomController::delete_announcement');
    $router->post('/classrooms/{id}/items', 'ClassroomController::create_item');
    $router->delete('/classroom-items/{id}', 'ClassroomController::delete_item');
    $router->get('/classroom-items/{id}/submissions', 'ClassroomController::submissions');
    $router->post('/classroom-items/{id}/submission', 'ClassroomController::submit');
    $router->post('/classroom-submissions/{id}/grade', 'ClassroomController::grade');
    $router->get('/classroom-files/{id}', 'ClassroomController::file');
    $router->delete('/classroom-files/{id}', 'ClassroomController::delete_file');

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
    $router->get('/documents/{id}/view', 'DocumentArchiveController::view');
    $router->put('/documents/{id}', 'DocumentArchiveController::rename');
    $router->delete('/documents/{id}', 'DocumentArchiveController::delete');
});
