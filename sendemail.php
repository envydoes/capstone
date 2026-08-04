<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Manual include of PHPMailer files
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';
require 'PHPMailer/Exception.php';

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host = 'smtp.example.com';      // Your SMTP server
    $mail->SMTPAuth = true;
    $mail->Username = 'your_email@example.com';
    $mail->Password = 'your_password';
    $mail->SMTPSecure = 'tls';
    $mail->Port = 587;

    $mail->setFrom('from@example.com', 'Mailer');
    $mail->addAddress('recipient@example.com', 'Receiver');

    $mail->isHTML(true);
    $mail->Subject = 'Test Email';
    $mail->Body    = 'This is a test email.';

    $mail->send();

    // Redirect on success
    header('Location: thankyou.php');
    exit;

} catch (Exception $e) {
    // Redirect on error
    header('Location: error.php');
    exit;
}