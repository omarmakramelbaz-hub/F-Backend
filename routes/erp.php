<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Erp\AuthController;
use App\Http\Controllers\Erp\WorkspaceController;
use App\Http\Middleware\ErpAccess;

Route::prefix('erp')->name('erp.')->group(function () {
    Route::get('login', [AuthController::class, 'login'])->name('login');
    Route::post('login', [AuthController::class, 'signin'])->name('signin');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    Route::middleware(ErpAccess::class)->group(function () {
        Route::get('/', [WorkspaceController::class, 'home'])->name('home');
        Route::get('branches', [WorkspaceController::class, 'branches'])->name('branches');
        Route::post('branches', [WorkspaceController::class, 'saveBranch'])->name('branches.save');
        Route::post('warehouses', [WorkspaceController::class, 'saveWarehouse'])->name('warehouses.save');
        Route::get('inventory', [WorkspaceController::class, 'inventory'])->name('inventory');
        Route::post('items', [WorkspaceController::class, 'saveItem'])->name('items.save');
        Route::post('stock', [WorkspaceController::class, 'postStock'])->name('stock.post');
        Route::get('employees', [WorkspaceController::class, 'employees'])->name('employees');
        Route::post('employees', [WorkspaceController::class, 'saveEmployee'])->name('employees.save');
        Route::post('employees/{employee}/attendance', [WorkspaceController::class, 'attendance'])->whereNumber('employee')->name('attendance');
        Route::post('employees/{employee}/salary', [WorkspaceController::class, 'salary'])->whereNumber('employee')->name('salary');
        Route::get('payroll', [WorkspaceController::class, 'payroll'])->name('payroll');
        Route::post('employees/{employee}/adjustment', [WorkspaceController::class, 'adjustment'])->whereNumber('employee')->name('adjustment');
        Route::post('employees/{employee}/payroll', [WorkspaceController::class, 'closePayroll'])->whereNumber('employee')->name('payroll.close');
        Route::get('orders', [WorkspaceController::class, 'orders'])->name('orders');
        Route::get('accounts', [WorkspaceController::class, 'accounts'])->name('accounts');
        Route::post('accounts', [WorkspaceController::class, 'saveAccount'])->name('accounts.save');
        Route::get('audit', [WorkspaceController::class, 'audit'])->name('audit');
    });
});
