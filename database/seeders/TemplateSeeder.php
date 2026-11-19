<?php

namespace Database\Seeders;

use App\Models\Template;
use Illuminate\Database\Seeder;

class TemplateSeeder extends Seeder
{
    /**
     * Seeds global (admin) starter templates for every messaging module so clients can
     * filter by `type` (quick_sms, bulk_sms, campaign_promotion, campaign_occasion, survey)
     * and apply them right away. Safe to re-run (idempotent via updateOrCreate).
     */
    public function run(): void
    {
        $templates = [
            /*
            |--------------------------------------------------------------------------
            | Quick SMS
            |--------------------------------------------------------------------------
            */
            [
                'type' => Template::TYPE_QUICK_SMS,
                'category' => 'alerts',
                'name' => 'Verification Code (OTP)',
                'description' => 'Send a one-time verification code to a customer.',
                'body' => 'Your verification code is 123456. It expires in 10 minutes. Do not share this code with anyone.',
            ],
            [
                'type' => Template::TYPE_QUICK_SMS,
                'category' => 'orders',
                'name' => 'Order Ready for Pickup',
                'description' => 'Notify a customer that their order is ready.',
                'body' => 'Hello, your order is ready for pickup at our front desk. Please bring your order reference when coming.',
            ],
            [
                'type' => Template::TYPE_QUICK_SMS,
                'category' => 'reminders',
                'name' => 'Appointment Confirmation',
                'description' => 'Confirm a scheduled appointment date and time.',
                'body' => 'Your appointment has been confirmed for tomorrow at 10:00 AM. Reply or call us if you need to reschedule.',
            ],
            [
                'type' => Template::TYPE_QUICK_SMS,
                'category' => 'payments',
                'name' => 'Payment Receipt Confirmation',
                'description' => 'Confirm receipt of a customer payment.',
                'body' => 'Thank you! We have received your payment and updated your account. Contact our support desk if you need a receipt.',
            ],

            /*
            |--------------------------------------------------------------------------
            | Bulk SMS
            |--------------------------------------------------------------------------
            */
            [
                'type' => Template::TYPE_BULK_SMS,
                'category' => 'alerts',
                'name' => 'Personalized General Announcement',
                'description' => 'Broadcast an important update personalized with each contact first name.',
                'body' => 'Hi {firstName}, we have an important update regarding our services in {city}. Visit our office or reach out to support for details.',
            ],
            [
                'type' => Template::TYPE_BULK_SMS,
                'category' => 'reminders',
                'name' => 'Payment / Renewal Reminder',
                'description' => 'Remind customers about an upcoming invoice or subscription renewal.',
                'body' => 'Dear {fullName}, this is a friendly reminder that your account renewal is due soon. Please renew early to avoid service interruption.',
            ],
            [
                'type' => Template::TYPE_BULK_SMS,
                'category' => 'events',
                'name' => 'Event Reminder Notice',
                'description' => 'Send an event reminder to a contact group.',
                'body' => 'Hello {firstName}, reminder that our upcoming event in {region} starts tomorrow at 9:00 AM. We look forward to hosting you!',
            ],
            [
                'type' => Template::TYPE_BULK_SMS,
                'category' => 'alerts',
                'name' => 'Delivery Schedule Notice',
                'description' => 'Notify customers of scheduled delivery to their address.',
                'body' => 'Hi {firstName}, our dispatch rider is scheduled to deliver to {address} ({gpsAddress}). Please keep {phoneNumber} reachable.',
            ],

            /*
            |--------------------------------------------------------------------------
            | Campaign — Promotion
            |--------------------------------------------------------------------------
            */
            [
                'type' => Template::TYPE_CAMPAIGN_PROMOTION,
                'category' => 'sales',
                'name' => 'Flash Sale Discount',
                'description' => 'Drive urgent sales with a time-limited discount and tracked CTA link.',
                'body' => 'Hi {firstName}! Enjoy 20% OFF everything today only. Shop before midnight: {ctaUrl}',
                'cta' => [
                    'enabled' => true,
                    'type' => 'link',
                    'label' => 'Shop Now',
                    'url' => 'https://example.com/sale',
                ],
            ],
            [
                'type' => Template::TYPE_CAMPAIGN_PROMOTION,
                'category' => 'sales',
                'name' => 'New Product Launch',
                'description' => 'Introduce a new product or service to your audience.',
                'body' => 'Exciting news {firstName}! Our newest collection just landed in {city}. Be the first to check it out.',
                'cta' => [
                    'enabled' => true,
                    'type' => 'link',
                    'label' => 'Explore',
                    'url' => 'https://example.com/new-arrivals',
                ],
            ],
            [
                'type' => Template::TYPE_CAMPAIGN_PROMOTION,
                'category' => 'sales',
                'name' => 'Weekend Special Offer',
                'description' => 'Boost weekend foot traffic or online orders.',
                'body' => 'Hey {firstName}, treat yourself this weekend! Get free delivery anywhere in {region} on orders placed before Sunday.',
                'cta' => [
                    'enabled' => true,
                    'type' => 'link',
                    'label' => 'Order Now',
                    'url' => 'https://example.com/weekend-deals',
                ],
            ],
            [
                'type' => Template::TYPE_CAMPAIGN_PROMOTION,
                'category' => 'loyalty',
                'name' => 'USSD Quick Promo',
                'description' => 'Promote a service that customers can activate via USSD shortcode.',
                'body' => 'Hi {firstName}, unlock our exclusive bundle offer right from your phone today!',
                'cta' => [
                    'enabled' => true,
                    'type' => 'ussd',
                    'label' => 'Dial',
                    'value' => '*713#',
                ],
            ],

            /*
            |--------------------------------------------------------------------------
            | Campaign — Occasion (birthday, anniversary, holiday, custom)
            |--------------------------------------------------------------------------
            */
            [
                'type' => Template::TYPE_CAMPAIGN_OCCASION,
                'occasion_type' => 'birthday',
                'category' => 'greetings',
                'name' => 'Warm Birthday Greeting',
                'description' => 'Automatic yearly birthday wish sent on each contact date of birth.',
                'body' => 'Happy Birthday {firstName}! Wishing you joy, good health, and a fantastic year ahead. Thank you for being part of our family!',
            ],
            [
                'type' => Template::TYPE_CAMPAIGN_OCCASION,
                'occasion_type' => 'birthday',
                'category' => 'sales',
                'name' => 'Birthday Gift Discount',
                'description' => 'Celebrate a contact birthday with a special tracked discount link.',
                'body' => 'Happy Birthday {firstName}! Celebrate your special day with 15% off your next order. Claim your gift here: {ctaUrl}',
                'cta' => [
                    'enabled' => true,
                    'type' => 'link',
                    'label' => 'Claim Gift',
                    'url' => 'https://example.com/birthday-gift',
                ],
            ],
            [
                'type' => Template::TYPE_CAMPAIGN_OCCASION,
                'occasion_type' => 'anniversary',
                'category' => 'greetings',
                'name' => 'Customer Anniversary Appreciation',
                'description' => 'Thank loyal customers on your company or membership anniversary.',
                'body' => 'Happy Anniversary {firstName}! Thank you for trusting us all this time. We truly appreciate your loyalty.',
            ],
            [
                'type' => Template::TYPE_CAMPAIGN_OCCASION,
                'occasion_type' => 'holiday',
                'category' => 'greetings',
                'name' => 'Festive Season Greetings',
                'description' => 'Send warm holiday wishes to your entire audience.',
                'body' => 'Season Greetings {firstName}! Wishing you and your loved ones peace, joy, and happiness this holiday season.',
            ],
            [
                'type' => Template::TYPE_CAMPAIGN_OCCASION,
                'occasion_type' => 'custom',
                'category' => 'events',
                'name' => 'Special Milestone / Grand Opening',
                'description' => 'Invite contacts to a custom date celebration or store opening.',
                'body' => 'Hi {firstName}, join us as we celebrate our special milestone in {city}! Come enjoy exclusive giveaways and offers.',
                'cta' => [
                    'enabled' => true,
                    'type' => 'link',
                    'label' => 'RSVP',
                    'url' => 'https://example.com/rsvp',
                ],
            ],

            /*
            |--------------------------------------------------------------------------
            | Surveys
            |--------------------------------------------------------------------------
            */
            [
                'type' => Template::TYPE_SURVEY,
                'category' => 'feedback',
                'name' => 'Customer Satisfaction (CSAT) Survey',
                'description' => 'Measure overall customer satisfaction after a purchase or support interaction.',
                'intro' => 'We value your feedback! Please take 1 minute to tell us about your recent experience.',
                'body' => 'Hi {firstName}, how did we do? Share your quick feedback here: {surveyUrl}',
                'questions' => [
                    [
                        'prompt' => 'How satisfied are you with our service overall?',
                        'type' => 'rating',
                        'options' => null,
                        'required' => true,
                        'position' => 0,
                    ],
                    [
                        'prompt' => 'Was your issue or request resolved promptly?',
                        'type' => 'yes_no',
                        'options' => null,
                        'required' => true,
                        'position' => 1,
                    ],
                    [
                        'prompt' => 'What can we improve to serve you better?',
                        'type' => 'long_text',
                        'options' => null,
                        'required' => false,
                        'position' => 2,
                    ],
                ],
            ],
            [
                'type' => Template::TYPE_SURVEY,
                'category' => 'feedback',
                'name' => 'Net Promoter & Referral Survey',
                'description' => 'Find out how likely customers are to recommend your brand.',
                'intro' => 'Help us grow! Tell us what you love and what we can make even better.',
                'body' => 'Hello {firstName}, we would love your honest opinion! Take our 30-second survey: {surveyUrl}',
                'questions' => [
                    [
                        'prompt' => 'How likely are you to recommend us to a friend or colleague? (1 = Not likely, 5 = Very likely)',
                        'type' => 'rating',
                        'options' => null,
                        'required' => true,
                        'position' => 0,
                    ],
                    [
                        'prompt' => 'What do you like most about our products or services?',
                        'type' => 'single_choice',
                        'options' => ['Quality', 'Pricing', 'Customer Support', 'Fast Delivery'],
                        'required' => true,
                        'position' => 1,
                    ],
                    [
                        'prompt' => 'Any additional comments?',
                        'type' => 'text',
                        'options' => null,
                        'required' => false,
                        'position' => 2,
                    ],
                ],
            ],
            [
                'type' => Template::TYPE_SURVEY,
                'category' => 'events',
                'name' => 'Post-Event Feedback Survey',
                'description' => 'Collect attendee ratings and topic suggestions after an event.',
                'intro' => 'Thank you for attending! Let us know how the event went so we can make the next one even better.',
                'body' => 'Hi {firstName}, thanks for joining us! Share your event feedback here: {surveyUrl}',
                'questions' => [
                    [
                        'prompt' => 'How would you rate the overall event?',
                        'type' => 'rating',
                        'options' => null,
                        'required' => true,
                        'position' => 0,
                    ],
                    [
                        'prompt' => 'Which sessions or activities did you enjoy most?',
                        'type' => 'multiple_choice',
                        'options' => ['Keynote Speech', 'Networking', 'Workshops', 'Q&A Session'],
                        'required' => true,
                        'position' => 1,
                    ],
                    [
                        'prompt' => 'Would you attend our next event?',
                        'type' => 'yes_no',
                        'options' => null,
                        'required' => true,
                        'position' => 2,
                    ],
                ],
            ],
            [
                'type' => Template::TYPE_SURVEY,
                'category' => 'feedback',
                'name' => 'Product & Delivery Feedback',
                'description' => 'Check product quality and delivery speed after fulfillment.',
                'intro' => 'Your order was recently delivered — let us know if everything arrived in great shape!',
                'body' => 'Hi {firstName}, hope you are enjoying your order in {city}! Rate your delivery here: {surveyUrl}',
                'questions' => [
                    [
                        'prompt' => 'How would you rate the delivery speed?',
                        'type' => 'rating',
                        'options' => null,
                        'required' => true,
                        'position' => 0,
                    ],
                    [
                        'prompt' => 'Did your order arrive in good condition?',
                        'type' => 'yes_no',
                        'options' => null,
                        'required' => true,
                        'position' => 1,
                    ],
                    [
                        'prompt' => 'Tell us anything else about your delivery experience:',
                        'type' => 'text',
                        'options' => null,
                        'required' => false,
                        'position' => 2,
                    ],
                ],
            ],
        ];

        foreach ($templates as $row) {
            Template::query()->updateOrCreate(
                [
                    'scope' => Template::SCOPE_GLOBAL,
                    'client_id' => null,
                    'type' => $row['type'],
                    'name' => $row['name'],
                ],
                [
                    'occasion_type' => $row['occasion_type'] ?? null,
                    'category' => $row['category'] ?? null,
                    'description' => $row['description'] ?? null,
                    'intro' => $row['intro'] ?? null,
                    'body' => $row['body'],
                    'cta' => $row['cta'] ?? null,
                    'questions' => $row['questions'] ?? null,
                    'status' => Template::STATUS_ACTIVE,
                ],
            );
        }
    }
}
