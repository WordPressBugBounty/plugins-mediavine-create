<?php
namespace Mediavine\Create;

class Translation extends Plugin {
	public static function client_terms() {
		return [
			'COMMENTS'             => __( 'Comments', 'mediavine-create' ),
			'COMMENTS_AND_REVIEWS' => __( 'Comments & Reviews', 'mediavine-create' ),
			'RATING'               => __( 'Rating', 'mediavine-create' ),
			'REVIEWS'              => __( 'Reviews', 'mediavine-create' ),
			'RATING_SUBMITTED'     => __( 'Your rating has been submitted. Write a review below (optional).', 'mediavine-create' ),
			/* translators: Number of reviews for a card by title */
			'X_REVIEWS_FOR'        => __( '%1$s Reviews for %2$s', 'mediavine-create' ),
			/* translators: Number of reviews for a card by title */
			'X_REVIEW_FOR'         => __( '%1$s Review for %2$s', 'mediavine-create' ),
			'LOADING'              => __( 'Loading', 'mediavine-create' ),
			'VIEW_MORE'            => __( 'View More', 'mediavine-create' ),
			/* translators: Number of reviews */
			'NUM_REVIEW'           => __( '%s Review', 'mediavine-create' ),
			/* translators: Number of reviews */
			'NUM_REVIEWS'          => __( '%s Reviews', 'mediavine-create' ),
			'REVIEW'               => __( 'Review', 'mediavine-create' ),
			/* translators: Rating for a card */
			'NUM_STARS'            => __( '%s Stars', 'mediavine-create' ),
			'STARS'                => __( 'Stars', 'mediavine-create' ),
			'STAR'                 => __( 'Star', 'mediavine-create' ),
			'TITLE'                => __( 'Title', 'mediavine-create' ),
			'ANONYMOUS_USER'       => __( 'Anonymous User', 'mediavine-create' ),
			'NO_TITLE'             => __( 'No Title', 'mediavine-create' ),
			'CONTENT'              => __( 'Content', 'mediavine-create' ),
			'NO_RATINGS'           => __( 'No Ratings', 'mediavine-create' ),
			'NAME'                 => __( 'Name', 'mediavine-create' ),
			'EMAIL'                => __( 'Email', 'mediavine-create' ),
			'REVIEW_TITLE'         => __( 'Review Title', 'mediavine-create' ),
			'REVIEW_CONTENT'       => __( 'Review', 'mediavine-create' ),
			'CONSENT'              => __( 'To submit this review, I consent to the collection of this data.', 'mediavine-create' ),
			'SUBMIT_REVIEW'        => __( 'Submit Review', 'mediavine-create' ),
			'SUBMITTING'           => __( 'Submitting', 'mediavine-create' ),
			'UPDATE'               => __( 'Update Review', 'mediavine-create' ),
			'THANKS_RATING'        => __( 'Thanks for the rating!', 'mediavine-create' ),
			'DID_YOU_MAKE_THIS'    => __( 'Did you make this? Tell us about it!', 'mediavine-create' ),
			'LEAVE_REVIEW'         => __( 'Leave a review', 'mediavine-create' ),
			'THANKS_REVIEW'        => __( 'Thanks for the review!', 'mediavine-create' ),
			'PRINT'                => __( 'Print', 'mediavine-create' ),
			'YIELD'                => __( 'Yield', 'mediavine-create' ),
			'SERVING_SIZE'         => __( 'Serving Size', 'mediavine-create' ),
			'AMOUNT_PER_SERVING'   => __( 'Amount Per Serving', 'mediavine-create' ),
			'CUISINE'              => __( 'Cuisine', 'mediavine-create' ),
			'PROJECT_TYPE'         => __( 'Project Type', 'mediavine-create' ),
			'TYPE'                 => __( 'Type', 'mediavine-create' ),
			'CATEGORY'             => __( 'Category', 'mediavine-create' ),
			'RECOMMENDED_PRODUCTS' => __( 'Recommended Products', 'mediavine-create' ),
			'AFFILIATE_NOTICE'     => __( 'As an Amazon Associate and member of other affiliate programs, I earn from qualifying purchases.', 'mediavine-create' ),
			'TOOLS'                => __( 'Tools', 'mediavine-create' ),
			'MATERIALS'            => __( 'Materials', 'mediavine-create' ),
			'INGREDIENTS'          => __( 'Ingredients', 'mediavine-create' ),
			'INSTRUCTIONS'         => __( 'Instructions', 'mediavine-create' ),
			'NOTES'                => __( 'Notes', 'mediavine-create' ),
			'CALORIES'             => __( 'Calories', 'mediavine-create' ),
			'TOTAL_FAT'            => __( 'Total Fat', 'mediavine-create' ),
			'SATURATED_FAT'        => __( 'Saturated Fat', 'mediavine-create' ),
			'TRANS_FAT'            => __( 'Trans Fat', 'mediavine-create' ),
			'UNSATURATED_FAT'      => __( 'Unsaturated Fat', 'mediavine-create' ),
			'CHOLESTEROL'          => __( 'Cholesterol', 'mediavine-create' ),
			'SODIUM'               => __( 'Sodium', 'mediavine-create' ),
			'CARBOHYDRATES'        => __( 'Carbohydrates', 'mediavine-create' ),
			'NET_CARBOHYDRATES'    => __( 'Net Carbohydrates', 'mediavine-create' ),
			'FIBER'                => __( 'Fiber', 'mediavine-create' ),
			'SUGAR'                => __( 'Sugar', 'mediavine-create' ),
			'SUGAR_ALCOHOLS'       => __( 'Sugar Alcohols', 'mediavine-create' ),
			'PROTEIN'              => __( 'Protein', 'mediavine-create' ),
			'RESPONSES'            => __( 'Responses', 'mediavine-create' ),
			'REPLY'                => __( 'Reply', 'mediavine-create' ),
			'CANCEL_RESPONSE'      => __( 'Cancel Reply', 'mediavine-create' ),
			'YOUR_RESPONSE'        => __( 'Your Response', 'mediavine-create' ),
			'WRITE_YOUR_RESPONSE'  => __( 'Write your response...', 'mediavine-create' ),
			'SUBMIT_RESPONSE'      => __( 'Submit Response', 'mediavine-create' ),
			'CANCEL'               => __( 'Cancel', 'mediavine-create' ),
			'NAME_REQUIRED'        => __( 'Name is required', 'mediavine-create' ),
			'RESPONSE_REQUIRED'    => __( 'Response is required', 'mediavine-create' ),
			'ADMIN'                => __( 'Admin', 'mediavine-create' ),
			'URLS_NOT_ALLOWED'     => __( 'URLs are not allowed in reviews', 'mediavine-create' ),
		];
	}

	public static function admin_terms() {
		return [];
	}

}
