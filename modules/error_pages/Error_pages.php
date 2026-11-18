<?php
class Error_pages extends Trongate {
    function not_found(): void {
        http_response_code(404);
        echo 'Not found';
    }
}
