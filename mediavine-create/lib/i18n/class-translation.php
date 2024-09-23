<?php
namespace Mediavine\Create;

class Translation extends Plugin {
	public static function client_terms() {
		return [
			'COMMENTS'             => __( 'Comments', 'mediavine' ),
			'COMMENTS_AND_REVIEWS' => __( 'Comments & Reviews', 'mediavine' ),
			'RATING'               => __( 'Rating', 'mediavine' ),
			'REVIEWS'              => __( 'Reviews', 'mediavine' ),
			'RATING_SUBMITTED'     => __( 'Your rating has been submitted. Write a review below (optional).', 'mediavine' ),
			/* translators: Number of reviews for a card by title */
			'X_REVIEWS_FOR'        => __( '%1$s Reviews for %2$s', 'mediavine' ),
			/* translators: Number of reviews for a card by title */
			'X_REVIEW_FOR'         => __( '%1$s Review for %2$s', 'mediavine' ),
			'LOADING'              => __( 'Loading', 'mediavine' ),
			'VIEW_MORE'            => __( 'View More', 'mediavine' ),
			/* translators: Number of reviews */
			'NUM_REVIEW'           => __( '%s Review', 'mediavine' ),
			/* translators: Number of reviews */
			'NUM_REVIEWS'          => __( '%s Reviews', 'mediavine' ),
			'REVIEW'               => __( 'Review', 'mediavine' ),
			/* translators: Rating for a card */
			'NUM_STARS'            => __( '%s Stars', 'mediavine' ),
			'STARS'                => __( 'Stars', 'mediavine' ),
			'STAR'                 => __( 'Star', 'mediavine' ),
			'TITLE'                => __( 'Title', 'mediavine' ),
			'ANONYMOUS_USER'       => __( 'Anonymous User', 'mediavine' ),
			'NO_TITLE'             => __( 'No Title', 'mediavine' ),
			'CONTENT'              => __( 'Content', 'mediavine' ),
			'NO_RATINGS'           => __( 'No Ratings', 'mediavine' ),
			'NAME'                 => __( 'Name', 'mediavine' ),
			'EMAIL'                => __( 'Email', 'mediavine' ),
			'REVIEW_TITLE'         => __( 'Review Title', 'mediavine' ),
			'REVIEW_CONTENT'       => __( 'Review', 'mediavine' ),
			'CONSENT'              => __( 'To submit this review, I consent to the collection of this data.', 'mediavine' ),
			'SUBMIT_REVIEW'        => __( 'Submit Review', 'mediavine' ),
			'SUBMITTING'           => __( 'Submitting', 'mediavine' ),
			'UPDATE'               => __( 'Update Review', 'mediavine' ),
			'THANKS_RATING'        => __( 'Thanks for the rating!', 'mediavine' ),
			'DID_YOU_MAKE_THIS'    => __( 'Did you make this? Tell us about it!', 'mediavine' ),
			'LEAVE_REVIEW'         => __( 'Leave a review', 'mediavine' ),
			'THANKS_REVIEW'        => __( 'Thanks for the review!', 'mediavine' ),
			'PRINT'                => __( 'Print', 'mediavine' ),
			'YIELD'                => __( 'Yield', 'mediavine' ),
			'SERVING_SIZE'         => __( 'Serving Size', 'mediavine' ),
			'AMOUNT_PER_SERVING'   => __( 'Amount Per Serving', 'mediavine' ),
			'CUISINE'              => __( 'Cuisine', 'mediavine' ),
			'PROJECT_TYPE'         => __( 'Project Type', 'mediavine' ),
			'TYPE'                 => __( 'Type', 'mediavine' ),
			'CATEGORY'             => __( 'Category', 'mediavine' ),
			'RECOMMENDED_PRODUCTS' => __( 'Recommended Products', 'mediavine' ),
			'AFFILIATE_NOTICE'     => __( 'As an Amazon Associate and member of other affiliate programs, I earn from qualifying purchases.', 'mediavine' ),
			'TOOLS'                => __( 'Tools', 'mediavine' ),
			'MATERIALS'            => __( 'Materials', 'mediavine' ),
			'INGREDIENTS'          => __( 'Ingredients', 'mediavine' ),
			'INSTRUCTIONS'         => __( 'Instructions', 'mediavine' ),
			'NOTES'                => __( 'Notes', 'mediavine' ),
			'CALORIES'             => __( 'Calories', 'mediavine' ),
			'TOTAL_FAT'            => __( 'Total Fat', 'mediavine' ),
			'SATURATED_FAT'        => __( 'Saturated Fat', 'mediavine' ),
			'TRANS_FAT'            => __( 'Trans Fat', 'mediavine' ),
			'UNSATURATED_FAT'      => __( 'Unsaturated Fat', 'mediavine' ),
			'CHOLESTEROL'          => __( 'Cholesterol', 'mediavine' ),
			'SODIUM'               => __( 'Sodium', 'mediavine' ),
			'CARBOHYDRATES'        => __( 'Carbohydrates', 'mediavine' ),
			'NET_CARBOHYDRATES'    => __( 'Net Carbohydrates', 'mediavine' ),
			'FIBER'                => __( 'Fiber', 'mediavine' ),
			'SUGAR'                => __( 'Sugar', 'mediavine' ),
			'SUGAR_ALCOHOLS'       => __( 'Sugar Alcohols', 'mediavine' ),
			'PROTEIN'              => __( 'Protein', 'mediavine' ),
		];
	}

	public static function admin_terms() {
		return [];
	}

}
