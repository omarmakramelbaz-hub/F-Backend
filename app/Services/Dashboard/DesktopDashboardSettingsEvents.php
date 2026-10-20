<?php
namespace App\Services\Dashboard;

use App\Models\GeneralSettings;
use Illuminate\Contracts\Events\Dispatcher;
use Spatie\LaravelSettings\Events\SettingsSaved;

/** Preserve normal dispatch; publish a reviewed settings save only after its root commit. */
final class DesktopDashboardSettingsEvents implements Dispatcher
{
    public array $saved=[];
    public function __construct(private Dispatcher $original) {}
    public function listen($events,$listener=null){return $this->original->listen($events,$listener);}
    public function hasListeners($eventName){return $this->original->hasListeners($eventName);}
    public function subscribe($subscriber){return $this->original->subscribe($subscriber);}
    public function until($event,$payload=[]){return $this->dispatch($event,$payload,true);}
    public function dispatch($event,$payload=[],$halt=false){
        if($event instanceof SettingsSaved&&get_class($event->settings)===GeneralSettings::class){
            $this->saved=[new SettingsSaved(clone$event->settings)];return null;
        }
        return $this->original->dispatch($event,$payload,$halt);
    }
    public function push($event,$payload=[]){return $this->original->push($event,$payload);}
    public function flush($event){return $this->original->flush($event);}
    public function forget($event){return $this->original->forget($event);}
    public function forgetPushed(){return $this->original->forgetPushed();}
}
