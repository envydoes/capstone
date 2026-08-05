<?php echo getenv('BREVO_API_KEY') ? 'FOUND: ' . substr(getenv('BREVO_API_KEY'), 0, 15) . '...' : 'NOT FOUND'; ?>
