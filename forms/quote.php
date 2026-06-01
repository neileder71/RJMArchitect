<?php
/**
 * Quote Form Handler
 * Stores form data directly to MySQL database
 */

// Include database configuration
include 'db_config.php';

// Check if form is submitted via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Get and sanitize form data
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $type = isset($_POST['type']) ? trim($_POST['type']) : '';
    $lot_area = isset($_POST['lot_area']) ? trim($_POST['lot_area']) : '';
    $budget = isset($_POST['budget']) ? trim($_POST['budget']) : '';
    $message = isset($_POST['message']) ? trim($_POST['message']) : '';
    
    // Validation
    $errors = [];
    
    if (empty($name)) {
        $errors[] = 'Name is required';
    }
    
    if (empty($email)) {
        $errors[] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format';
    }
    
    if (empty($phone)) {
        $errors[] = 'Phone is required';
    }
    
    if (empty($type)) {
        $errors[] = 'Project type is required';
    }
    
    if (empty($lot_area)) {
        $errors[] = 'Lot area is required';
    }
    
    if (empty($budget)) {
        $errors[] = 'Project location is required';
    }
    
    if (empty($message)) {
        $errors[] = 'Project description is required';
    }
    
    // If there are validation errors, return them
    if (!empty($errors)) {
        die(implode(', ', $errors));
    }
    
    // Prepare and bind SQL statement (prevents SQL injection)
    $stmt = $conn->prepare("INSERT INTO quote_submissions (name, email, phone, project_type, lot_area, project_location, description, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
    
    if (!$stmt) {
        die('Error preparing statement: ' . $conn->error);
    }
    
    // Bind parameters
    $stmt->bind_param("sssssss", $name, $email, $phone, $type, $lot_area, $budget, $message);
    
    // Execute statement
    if ($stmt->execute()) {
        echo 'OK';
    } else {
        die('Error storing quote request: ' . $stmt->error);
    }
    
    // Close statement
    $stmt->close();
    
} else {
    // If not a POST request
    die('Invalid request method');
}

// Close database connection
$conn->close();
?>
