<!DOCTYPE html>
<html>
<head>
<title>Inventory System</title>
<?php
$csrfToken = isset($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
?>
 <!-- Font Awesome -->
 <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css" integrity="sha512-KfkfwYDsLkIlwQp6LFnl8zNdLGxu9YAA1QvwINks4PhcElQSvqcyVLLD9aMhXd13uQjoXtEKNosOWaZqXgel0g==" crossorigin="anonymous" referrerpolicy="no-referrer" />
<!-- Bootstrap -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" integrity="sha384-1BmE4kWBq78iYhFldvKuhfTAU6auU8tT94WrHftjDbrCEXSU1oBoqyl2QvZ6jIW3" crossorigin="anonymous">
<link rel="stylesheet" href="./css/style.css">
<!-- Font Awesome -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/js/all.min.js" integrity="sha512-6PM0qYu5KExuNcKt5bURAoT6KCThUmHRewN3zUFNaoI6Di7XJPTMoT6K0nsagZKk2OB4L7E3q1uQKHNHd4stIQ==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js" integrity="sha512-894YE6QWD5I59HgZOGReFYm4dnWc1Qt5NtvYSaNcOP+u1T9qYdvdihz0PPSiiqn/+/3e7Jo4EaG7TubfWGUrMQ==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-ka7Sk0Gln4gmtz2MlQnikT1wXgYsOg+OMhuP+IlRH9sENBO0LRn5q+8nbTov4+1p" crossorigin="anonymous"></script>
<script>
window.IMS_CSRF_TOKEN = "<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>";

(function($) {
    function appendCsrfToken(data) {
        if (!window.IMS_CSRF_TOKEN) {
            return data;
        }

        if (data instanceof FormData) {
            if (!data.has("csrf_token")) {
                data.append("csrf_token", window.IMS_CSRF_TOKEN);
            }
            return data;
        }

        if (typeof data === "string") {
            return data.indexOf("csrf_token=") === -1
                ? data + (data ? "&" : "") + "csrf_token=" + encodeURIComponent(window.IMS_CSRF_TOKEN)
                : data;
        }

        if ($.isPlainObject(data)) {
            if (typeof data.csrf_token === "undefined") {
                data.csrf_token = window.IMS_CSRF_TOKEN;
            }
            return data;
        }

        if (!data) {
            return { csrf_token: window.IMS_CSRF_TOKEN };
        }

        return data;
    }

    $.ajaxPrefilter(function(options) {
        var method = (options.type || "GET").toUpperCase();
        if (method !== "POST" || !window.IMS_CSRF_TOKEN) {
            return;
        }

        if (typeof options.data === "function") {
            var originalData = options.data;
            options.data = function() {
                return appendCsrfToken(originalData.apply(this, arguments));
            };
            return;
        }

        options.data = appendCsrfToken(options.data);
    });
})(jQuery);
</script>
<!-- jQuery -->
