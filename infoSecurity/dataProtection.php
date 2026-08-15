<?php
session_start();
include "../config/db_connection.php";
require_once __DIR__ . '/../includes/site_config.php';
$siteSettings = site_config_load($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="../assets/responsive-global.css">
  <title>Data Protection Notice - SumEste Portal</title>
  <link rel="icon" href="../assets/logo2.png" type="image/png">
  <script src="https://cdn.tailwindcss.com/3.4.16"></script>
  <link rel="stylesheet" href="/tailwind/input.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <?= site_config_css_vars($siteSettings) ?>
  <style>
    .bg-green-50 { background-color: var(--site-primary-pale) !important; }
    .border-green-100 { border-color: color-mix(in srgb, var(--site-primary) 20%, white) !important; }
    .text-green-700 { color: var(--site-primary) !important; }
    .text-green-900 { color: color-mix(in srgb, var(--site-primary) 75%, black) !important; }
    .text-green-500 { color: var(--site-primary) !important; }
  </style>
</head>
<body class="bg-green-50 min-h-screen">
  <div class="max-w-4xl mx-auto px-4 py-8">
    <a href="residentProfile.php" class="text-green-700 font-semibold text-sm"><i class="fa-solid fa-angle-left"></i> Back to Personal Information</a>
    <div class="mt-4 bg-white rounded-2xl border border-green-100 shadow p-8">
      <h1 class="text-3xl font-bold text-green-900">Data Protection Notice</h1>
      <p class="text-sm text-gray-500 mt-1">Last Updated: March 2026 | Barangay Sumacab Este, Cabanatuan City</p>
      <p class="text-sm text-gray-600 mt-4 bg-green-50 border border-green-100 rounded-lg p-3">
        <i class="fa-solid fa-circle-info text-green-500 mr-1"></i>
        This notice explains how the SumEste Portal collects, uses, stores, and protects the personal information submitted by users of the platform, in line with the Philippine Data Privacy Act of 2012 (Republic Act No. 10173).
      </p>
      <ol class="list-decimal pl-6 mt-6 space-y-4 text-gray-800 leading-relaxed">
        <li><strong>Collection of Personal Data.</strong> The system collects personal information necessary for providing barangay services, which may include full name, address, contact number, email address, date of birth, and identification documents.</li>
        <li>
          <strong>Purpose of Data Collection.</strong> The personal information collected will be used solely for the following purposes:
          <ul class="list-disc pl-6 mt-2 space-y-1 text-gray-700">
            <li>Verifying user identity and residency</li>
            <li>Processing barangay document requests</li>
            <li>Managing beneficiary program applications</li>
            <li>Recording equipment borrowing transactions</li>
            <li>Maintaining accurate resident records</li>
            <li>Supporting communication between barangay officials and users</li>
            <li>Generating reports and analytics for barangay decision-making</li>
          </ul>
        </li>
        <li><strong>Data Storage and Security.</strong> All personal information submitted through the system is securely stored in the barangay database, protected by authentication controls and restricted access.</li>
        <li><strong>Access to Personal Data.</strong> Access to personal information is limited to authorized barangay officials and system administrators. Personal data will not be shared with unauthorized third parties.</li>
        <li><strong>User Responsibility.</strong> Users are responsible for ensuring that the personal information they provide is accurate and up to date, and for protecting their account credentials.</li>
        <li><strong>Data Retention.</strong> Personal data will be retained only for as long as necessary to fulfill the purposes for which it was collected, or as required by barangay administrative procedures.</li>
        <li><strong>User Consent.</strong> By registering and submitting personal information through the system, users acknowledge and consent to the collection, processing, and storage of their personal data.</li>
        <li><strong>Compliance with Data Privacy Regulations.</strong> The system is designed to follow the principles of the Philippine Data Privacy Act of 2012 (Republic Act No. 10173).</li>
        <li><strong>Updates to this Notice.</strong> The barangay administration may update this Data Protection Notice when necessary to improve data protection practices or comply with applicable regulations.</li>
      </ol>
    </div>
  </div>
  <script>
    (function () {
      document.querySelectorAll("a[href]").forEach(function (link) {
        const href = link.getAttribute("href");
        if (!href || link.hasAttribute("data-nav")) return;
        const lower = href.toLowerCase();
        if (href.startsWith("#") || lower.startsWith("javascript:") || lower.startsWith("mailto:") || lower.startsWith("tel:")) return;
        link.setAttribute("data-nav", href);
        link.setAttribute("href", "javascript:void(0)");
        link.addEventListener("click", function (e) {
          e.preventDefault();
          const target = link.getAttribute("data-nav");
          if (!target) return;
          if (link.getAttribute("target") === "_blank") {
            window.open(target, "_blank", "noopener");
          } else {
            window.location.href = target;
          }
        });
      });
    })();
  </script>
</body>
</html>