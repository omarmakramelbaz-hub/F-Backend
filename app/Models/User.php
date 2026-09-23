<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\HasMedia;
use App\Enums\CategoryTypeEnum;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Support\Facades\DB;
use App\Scopes\AdminScope;
use Illuminate\Database\Eloquent\Model;
use Auth;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Ticket;
use App\Models\SocialAccount;
class User extends Authenticatable implements JWTSubject, HasMedia{
	use HasFactory, Notifiable,HasRoles;
    use InteractsWithMedia;

    /**
     * Get the social accounts for the user.
     */
    public function socialAccounts()
    {
        return $this->hasMany(SocialAccount::class);
    }
	protected $table = 'users';
	protected $guard_name = 'admin';
	protected $guarded = ['partner_auth_email'];
	 protected static function booted()
    {           
        static::addGlobalScope(new AdminScope);
        static::deleted(function ($user) {
            // Delete all related notifications
            $user->notifications()->delete();
        });
    }

	protected $hidden = [
		'password',
		'remember_token',
		'partner_auth_email',
	];
   
    protected static function boot()
    {
        parent::boot();
        static::deleting(function ($user) {
            // Delete notifications related to this order
            DB::table('notifications')
              ->where('notifiable_type', self::class)
              ->where('notifiable_id', $user->id)
              ->delete();
        });
    }
	
	private function activationCode()
    {
        return mt_rand(1111, 9999);
    }

    /**
     * First-order OTP verification is disabled.
     * Returning 1 makes checkout continue directly to order creation.
     */
    public function getOtpFirstOrderAttribute($value)
    {
        return 1;
    }

	public function setPasswordAttribute($password)
    {
        if ($password) {
            $this->attributes['password'] = bcrypt($password);
        }
    }
	/**
	 * The attributes that should be cast to native types.
	 *
	 * @var array
	 */
	protected $casts = [
		'email_verified_at' => 'datetime',        'balance' => 'double', // This is optional since we handle it in the accessor

	];
    public function getGravatarAttribute()
    {
        $hash = md5(strtolower(trim('1ahmedhelal1@gmail.com')));
        return "http://www.gravatar.com/avatar/$hash";
    }

	/**
	 * Get the identifier that will be stored in the subject claim of the JWT.
	 *
	 * @return mixed
	 */
	public function getJWTIdentifier() {
		return $this->getKey();
	}

	/**
	 * Return a key value array, containing any custom claims to be added to the JWT.
	 *
	 * @return array
	 */
	 public function getJWTCustomClaims()
    {
        return [];
    }

   
    public function category() {
       return $this->belongsTo(Category::class);
    }
    public function category_type() {
       return $this->belongsTo(CategoryType::class);
    }

   	public function user_resturants() {
       return $this->hasMany(Resturant::class,'user_id');
    }
    
    // public function base_resturant() {
    //   return $this->hasOne(Resturant::class,'user_id')->whereNull('parent_id');
    // }
    public function base_resturant() {
       return $this->hasOne(Resturant::class,'user_id');
    }
 	public function categories(){
 	    return $this->hasMany(Category::class,'admin_id');
 	}
    
    public function area(){
        return $this->belongsTo(Area::class,'area_id');
    }
    
    public function addresses(){
        return $this->hasMany(UserAddress::class,'user_id');
    }
    
    public function to_user(){
        return $this->hasMany(Wallet::class,'to_user');
    }
    public function from_user(){
        return $this->hasMany(Wallet::class,'from_user');
    }
    
    public function wishlists(){
        return $this->hasMany(Wishlist::class,'user_id');
    }
    public function commissions(){
        return $this->hasMany(Commission::class,'user_id');
    }
    
    public function delegate_commissions(){
        return $this->hasMany(Commission::class,'delegate_id');
    }

    public function orders(){
        return $this->hasMany(Order::class,'user_id');
    }
    public function carts(){
        return $this->hasMany(Cart::class,'user_id');
    }
     public function wallet(){
        return $this->hasMany(Wallet::class,'from_user');
    }
    public function user_transactions(){
        $user_transactions = \App\Models\Wallet::where('status', 'completed')
            ->where(function($query) {
                $query->where('from_user', $this->id)
                      ->orWhere('to_user', $this->id);
            })
            ->latest()
            ->get();
    return $user_transactions;
    }
    public function pending_order(){
        return $this->orders->whereNull('status')->first();
    }
    
    public function delegate_orders(){
        return $this->hasMany(Order::class,'delegate_id');
    }
    
    public function branches(){
        $resturants = \App\Models\Resturant::where('user_id',$this->id)->orWhere('parent_id', $this->base_resturant?->id)->get();
        return $resturants;
    }
    public function owner_resturant() {
       return $this->belongsTo(Resturant::class,'owner_resturant_id');
    }
      public function pending_vendor() {
       return $this->belongsTo(PendingVendor::class,'pending_vendor_id');
    }
    
    public function getGoDriveBlockAttribute(){
        $count_block_orders=$this->orders()->whereNotNull('shipping_cancelled_block')->count();
        $setting=app(GeneralSettings::class);
        if($count_block_orders<$setting->shipping_cancelled_block_no){
            return 0;
        }
        return 1;
    }

    public function tokens(){
        return $this->hasMany(UserToken::class,'user_id');
    }

    public function getMyTokensAttribute(){
        return $this->tokens()->get('token')->toArray();
    }

    public function newOrExistingToken($token)
    {
        $userToken = $this->tokens()->where('token', $token)->first();
        if ($userToken) {
            return $userToken;
        }

        if ($this->tokens()->count() >= 5) {
            $latestIds = $this->tokens()->latest()->take(4)->pluck('id');
            $this->tokens()->whereNotIn('id', $latestIds)->delete();
        }

        return $this->tokens()->create(['token' => $token]);
    }
}
