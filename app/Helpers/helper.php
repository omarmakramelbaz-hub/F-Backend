<?php
use Illuminate\Support\Facades\App;
use Carbon\Carbon; 
if (!function_exists('available_languages')) {
  function available_languages() {
    return ['ar', 'en'];
  }
}


function resource_collection($resource): array
    {
        return json_decode($resource->response()->getContent(), true) ?? [];
    }
    
function generateRandomCode(){
    return rand(0,99999);
}

 use GuzzleHttp\Client;
use Illuminate\Support\Facades\Http;

 function getCitiesInCountry()
    {
        if(\App\Services\OwnerDashboards\Runtime::tenant()&&!config('services.maps.browser_key'))return [];
        $cities = [];
        $nextPageToken = null;

        do {
            $response = Http::get('https://maps.googleapis.com/maps/api/place/textsearch/json', [
                'query' => 'city in Egypt',
                'key' => (\App\Services\OwnerDashboards\Runtime::tenant()?config('services.maps.browser_key'):'AIzaSyBDfirv6d7BiO-9YnsU5zCHEn9iM9iNtuM'),
                'pagetoken' => $nextPageToken,
            ]);

            $data = $response->json();

            foreach ($data['results'] as $result) {
                if (isset($result['formatted_address'])) {
                    $addressComponents = $result['address_components'];
                    foreach ($addressComponents as $component) {
                        if (in_array('locality', $component['types'])) {
                            $cities[] = $component['long_name'];
                        }
                    }
                }
            }

            $nextPageToken = $data['next_page_token'] ?? null;

            // Sleep to avoid hitting the API rate limit
            sleep(2);
        } while ($nextPageToken);

        return array_unique($cities);
    }

function getCityName($latitude,$longitude){
    if(\App\Services\OwnerDashboards\Runtime::tenant()&&!config('services.maps.browser_key'))return null;
    $client = new Client();
                        $ignoreWords = ['ثان','مركز' ,'مدينة','قسم', 'اول','أول'];

       $url = 'https://maps.googleapis.com/maps/api/geocode/json';
        $response = $client->get($url, [
            'query' => [
                'latlng' => "{$latitude},{$longitude}",
                'key' => (\App\Services\OwnerDashboards\Runtime::tenant()?config('services.maps.browser_key'):'AIzaSyBDfirv6d7BiO-9YnsU5zCHEn9iM9iNtuM'),
                'language' => 'ar' // Request results in Arabic
            ]
        ]);
        
        $data = json_decode($response->getBody()->getContents(), true);
        if ($data['status'] === 'OK') {
            foreach ($data['results'] as $result) {
                // dd($result['address_components']);
                foreach ($result['address_components'] as $component) {
                    // dd($component['types']);
                    if (in_array('locality', $component['types'])) {
                        $text = ($component['long_name']);

// Remove words
$filteredText = str_replace($ignoreWords, '', $text);

// Optionally, you may want to clean up extra spaces
$area = preg_replace('/\s+/', ' ', trim($filteredText));

                        return $area;
                    }elseif (in_array('administrative_area_level_2', $component['types'])) {
                        $text = ($component['long_name']);

// Remove words
$filteredText = str_replace($ignoreWords, '', $text);

// Optionally, you may want to clean up extra spaces
$area = preg_replace('/\s+/', ' ', trim($filteredText));

                        return $area;
                    }
                }
            }
        }
        return null;
}

function status_requests_trans($status){
  if($status == '0'){
            $status = trans('main.pending');
        }elseif($status == '1'){
            $status = trans('main.accepted');
        }elseif($status == '2'){
            $status = trans('main.inprogress');
        }elseif($status == '3'){
            $status = trans('main.completed');
        }elseif($status == '5'){
            $status = trans('main.inlocation');
        }else{
            $status = trans('main.declined');
        }

  return $status;
}
function status_offers_trans($status){
  if($status == 'pending'){
            $status = trans('main.pending');
        }elseif($status == 'accept'){
            $status = trans('main.accepted');
        }elseif($status == 'completed'){
            $status = trans('main.completed');
        }
        elseif($status == 'refused'){
            $status = trans('main.declined');
        }

  return $status;
}

function days_trans($day_name){
    switch ($day_name) {
      case "1":
        $day_name = trans('main.saturday');
        break;
      case "2":
        $day_name = trans('main.sunday');
        break;
      case "3":
        $day_name = trans('main.monday');
        break;
      case "4":
        $day_name = trans('main.tuesday');
        break;
      case "5":
        $day_name = trans('main.wednesday');
        break;
      case "6":
        $day_name = trans('main.thursday');
        break;
      case "7":
        $day_name = trans('main.friday');
        break;
    }
    return $day_name;
}


function account_type_trans($account_type){
    if($account_type == 'volunteer'){
        $account_type = trans('main.volunteer');
    }elseif($account_type == 'user'){
        $account_type = trans('main.user');
    }elseif($account_type == 'partner'){
        $account_type = trans('main.partner');
    }
    return $account_type;   
}

function rating_trans($rate){
  if($rate == '1'){
    $rate = trans('main.very bad');
  }elseif($rate == '2'){
    $rate = trans('main.bad');
  }elseif($rate == '3'){
    $rate = trans('main.good');
  }elseif($rate == '4'){
    $rate = trans('main.very good');
  }else{
    $rate = trans('main.excellent');
  }

  return $rate;
}


function slug($string, $separator = '-') {
    if (is_null($string)) {
    return "";
    }

    $string = trim($string);

    $string = mb_strtolower($string, "UTF-8");;

    $string = preg_replace("/[^a-z0-9_\sءاأإآؤئبتثجحخدذرزسشصضطظعغفقكلمنهويةى]#u/", "", $string);

    $string = preg_replace("/[\s-]+/", " ", $string);

    $string = preg_replace("/[\s_]/", $separator, $string);

  return $string;
}


function ArabicDate($v_date) {
    $months = array("Jan" => "يناير", "Feb" => "فبراير", "Mar" => "مارس", "Apr" => "أبريل", "May" => "مايو", "Jun" => "يونيو", "Jul" => "يوليو", "Aug" => "أغسطس", "Sep" => "سبتمبر", "Oct" => "أكتوبر", "Nov" => "نوفمبر", "Dec" => "ديسمبر");
    $your_date = $v_date; // The Current Date
    $en_month = Carbon::parse($your_date)->format('M');
    foreach ($months as $en => $ar) {
        if ($en == $en_month) { $ar_month = $ar; }
    }

    $find = array ("Sat", "Sun", "Mon", "Tue", "Wed" , "Thu", "Fri");
    $replace = array ("السبت", "الأحد", "الإثنين", "الثلاثاء", "الأربعاء", "الخميس", "الجمعة");
    $ar_day_format = date('D'); // The Current Day
    $ar_day = str_replace($find, $replace, $ar_day_format);
    header('Content-Type: text/html; charset=utf-8');
    $standard = array("0","1","2","3","4","5","6","7","8","9");
    $eastern_arabic_symbols = array("٠","١","٢","٣","٤","٥","٦","٧","٨","٩");
    $current_date =Carbon::parse($your_date)->format('j').' '.$ar_month.' '.Carbon::parse($your_date)->format('Y');
    $arabic_date = str_replace($standard , $eastern_arabic_symbols , $current_date);

    return $arabic_date;
}




function permissionArrayLoop(){

    $permissions = ['admin','role','home','banner','wallet','feature','setting','support_contact','coupon_wheel','areas','paymob','fcm_notification','slidear','resturant_owner','vendor','delegate','user','contract','category','product','resturant','pending_vendor' , 'question_answer','contact','order','report'];
    return $permissions;
}
 function shorten_URL ($longUrl) {
  $key = 'AIzaSyCiHHQPjy6oeKoN9H-j1LNEBZnwIu7n1pQ';
  $url = 'https://firebasedynamiclinks.googleapis.com/v1/shortLinks?key=' . $key;
  $data = array(
     "dynamicLinkInfo" => array(
        "dynamicLinkDomain" => "freshcoupnn.page.link",
        "link" => $longUrl
     )
  );

  $headers = array('Content-Type: application/json');

  $ch = curl_init ();
  curl_setopt ( $ch, CURLOPT_URL, $url );
  curl_setopt ( $ch, CURLOPT_POST, true );
  curl_setopt ( $ch, CURLOPT_HTTPHEADER, $headers );
  curl_setopt ( $ch, CURLOPT_RETURNTRANSFER, true );
  curl_setopt ( $ch, CURLOPT_POSTFIELDS, json_encode($data) );

  $data = curl_exec ( $ch );
  curl_close ( $ch );

  $short_url = json_decode($data);
  if(isset($short_url->error)){
      return $short_url->error->message;
  } else {
      return $short_url->shortLink;
  }

}


?>