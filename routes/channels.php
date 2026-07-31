<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('branch.{branchId}.orders', fn ($user, $branchId) => (int) $user->branch_id === (int) $branchId);
