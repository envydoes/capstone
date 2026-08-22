<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="../assets/responsive-global.css">
  <title>Terms and Conditions - SumEste Portal</title>
  <link rel="icon" href="../assets/logo2.png" type="image/png">
  <link rel="stylesheet" href="/tailwind/input.css">
  <link rel="stylesheet" href="/tailwind/input.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="dist/output.css">
    <script src="https://cdn.tailwindcss.com/3.4.16"></script>
</head>
<body class="bg-green-50 min-h-screen">
  <div class="max-w-4xl mx-auto px-4 py-8">
    <a href="residentProfile.php" class="text-green-700 font-semibold text-sm"><i class="fa-solid fa-angle-left"></i> Back to Personal Information</a>
    <div class="mt-4 bg-white rounded-2xl border border-green-100 shadow p-8">
      <h1 class="text-3xl font-bold text-green-900">Terms of Service</h1>
      <p class="text-sm text-gray-500 mt-1">Last Updated: March 2026 | Barangay Sumacab Este, Cabanatuan City</p>
      <ol class="list-decimal pl-6 mt-6 space-y-4 text-gray-800 leading-relaxed">
        <li><strong>Acceptance of Terms.</strong> By using this system, you agree to these terms.</li>
        <li><strong>Purpose of the System.</strong> This platform supports barangay services and digital requests.</li>
        <li><strong>User Responsibilities.</strong> Users must submit truthful and complete information.</li>
        <li><strong>Account Security.</strong> Keep your credentials confidential.</li>
        <li><strong>Use of Services.</strong> Misuse, fraud, spam, and unauthorized access are prohibited.</li>
        <li><strong>Verification and Approval.</strong> Some requests may require barangay review and approval.</li>
        <li><strong>Data Collection and Privacy.</strong> Personal data is processed for service delivery.</li>
        <li><strong>Content Responsibility.</strong> Users are responsible for submitted information and files.</li>
        <li><strong>System Availability.</strong> Service may be interrupted due to maintenance or technical issues.</li>
        <li><strong>Modification of Terms.</strong> Terms may be updated when necessary.</li>
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
