<?php
class Error_pages extends Trongate {
    function not_found(): void {
        $this->templates->error_404();
    }
}
