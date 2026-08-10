<?php
// Copia este archivo como stripe-config.php y rellena tus claves reales
// Consíguelas en: https://dashboard.stripe.com/test/apikeys

define('STRIPE_SECRET_KEY', 'sk_test_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX');
define('SITE_URL', 'https://hasumasajes.com');
// Misma apiKey pública del firebaseConfig en admin.html/index.html (no es secreta).
define('FIREBASE_WEB_API_KEY', 'AIzaSyBYTZfhePaKZQw9PJm4CdOhcr_-IHjaAgw');

// GA4 Measurement Protocol (server-side tracking) — Admin GA4 → Flujo de datos →
// API secrets de Measurement Protocol para generar el api_secret.
define('GA4_MEASUREMENT_ID', 'G-XXXXXXXXXX');
define('GA4_API_SECRET', 'REEMPLAZAR_API_SECRET');
