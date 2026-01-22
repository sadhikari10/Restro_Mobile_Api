<?php
session_start();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Privacy Policy | Restaurant Signup</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="admin.css" rel="stylesheet">
  <style>
    body {
      background: #fff8f2;
      font-family: 'Poppins', sans-serif;
    }
    .policy-container {
      max-width: 900px;
      background: #ffffff;
      margin: 60px auto;
      padding: 40px;
      border-radius: 15px;
      box-shadow: 0 5px 20px rgba(0,0,0,0.08);
    }
    .policy-header {
      text-align: center;
      margin-bottom: 40px;
    }
    .policy-header h2 {
      color: #d35400;
      font-weight: 700;
    }
    .policy-header p {
      color: #6c757d;
      font-size: 0.95rem;
    }
    h5 {
      color: #333;
      margin-top: 25px;
      font-weight: 600;
    }
    ul {
      margin-top: 10px;
    }
    .btn-back {
      background-color: #d35400;
      border: none;
      color: #fff;
      padding: 10px 20px;
      border-radius: 10px;
      transition: background-color 0.3s ease;
    }
    .btn-back:hover {
      background-color: #b94600;
    }
  </style>
</head>
<body>

  <div class="container policy-container">
    <div class="policy-header">
      <!-- <img src="../Assets/restaurant-logo.png" alt="Logo" height="70" class="mb-3"> -->
      <h2>Privacy Policy</h2>
      <p>Your privacy is important to us. Please read how we handle your data carefully.</p>
    </div>

    <div class="policy-content">
      <h5>1. Introduction</h5>
      <p>
        This Privacy Policy describes how we collect, use, and protect the personal and business information 
        of restaurant owners and their staff when using our Restaurant Management System (“the Service”).
      </p>

      <h5>2. Information We Collect</h5>
      <ul>
        <li>Restaurant details (name, address, contact information, email)</li>
        <li>User details (username, phone number, role, and password — encrypted)</li>
        <li>Order and billing data related to restaurant operations</li>
        <li>Technical information like IP address and device type for security purposes</li>
      </ul>

      <h5>3. How We Use Your Information</h5>
      <ul>
        <li>To create and manage your restaurant account</li>
        <li>To provide system access for restaurant staff and admins</li>
        <li>To process orders, generate reports, and manage billing</li>
        <li>To communicate important updates about your account or services</li>
      </ul>

      <h5>4. Data Security</h5>
      <p>
        We take your data security seriously. All passwords are encrypted and we use secure connections (HTTPS)
        to protect your information from unauthorized access, alteration, or disclosure.
      </p>

      <h5>5. Data Sharing</h5>
      <p>
        We do not sell, trade, or share your data with third parties. However, we may share data if required 
        by law or to protect the rights and safety of our platform and users.
      </p>

      <h5>6. Cookies</h5>
      <p>
        Our website may use cookies to improve your user experience and keep you logged in securely. 
        You can disable cookies in your browser settings, though some features may not function properly.
      </p>

      <h5>7. Data Retention</h5>
      <p>
        We retain your restaurant and account data as long as your subscription remains active. 
        Upon account termination, we may retain limited information for legal and accounting purposes.
      </p>

      <h5>8. Your Rights</h5>
      <p>
        You have the right to access, update, or delete your personal data. You can also request 
        information on how your data is stored or used by contacting our support team.
      </p>

      <h5>9. Report generation data</h5>
      <p>
       All financial figures are generated from user-entered data.
       The software provider does not modify business records.
      </p>

      <h5>10. Changes to This Policy</h5>
      <p>
        We may update this Privacy Policy from time to time. Any changes will be reflected on this page 
        with the updated date. Continued use of our system means you accept the revised terms.
      </p>

      <h5>11. Contact Us</h5>
      <p>
        If you have any questions about our Privacy Policy, please reach out to us using the official contact 
        methods available on our website or dashboard.
      </p>

      <h5>Note</h5>
      <p>
        The Superadmin role exists solely for system administration, maintenance, and support.
        All financial data including sales, purchases, taxes, and reports are entered and controlled by the restaurant administration.
        The software provider does not alter business records without explicit authorization.  
      </p>

      <div class="text-center mt-4">
        <?php 
          $backLink = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'signup.php';
        ?>
        <a href="<?= htmlspecialchars($backLink) ?>" class="btn btn-back">
          <i class="bi bi-arrow-left"></i> Back
        </a>
      </div>

    </div>
  </div>

  <?php include('footer.php'); ?>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
