<?php

it('inspires artisans', function (): void {
    $this->artisan('inspire')->assertExitCode(0);
});
