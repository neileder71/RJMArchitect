<?php
/**
 * Contact Form Handler
 * Stores form data directly to MySQL database
 */

// Include database configuration
include 'db_config.php';

// Check if form is submitted via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Get and sanitize form data
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $subject = isset($_POST['subject']) ? trim($_POST['subject']) : '';
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
    
    if (empty($subject)) {
        $errors[] = 'Subject is required';
    }
    
    if (empty($message)) {
        $errors[] = 'Message is required';
    }
    
    // If there are validation errors, return them
    if (!empty($errors)) {
        die(implode(', ', $errors));
    }
    
    // Prepare and bind SQL statement (prevents SQL injection)
    $stmt = $conn->prepare("INSERT INTO contact_submissions (name, email, subject, message, created_at) VALUES (?, ?, ?, ?, NOW())");
    
    if (!$stmt) {
        die('Error preparing statement: ' . $conn->error);
    }
    
    // Bind parameters
    $stmt->bind_param("ssss", $name, $email, $subject, $message);
    
    // Execute statement
    if ($stmt->execute()) {
        echo 'OK';
    } else {
        die('Error storing message: ' . $stmt->error);
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
