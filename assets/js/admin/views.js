
wpmoly.View = wp.Backbone.View;

/**
 * WPMOLY Admin Movie Grid View
 * 
 * This View renders the Admin Movie Grid.
 * 
 * @since    2.1.5
 */
wpmoly.view.Frame = wp.media.View.extend({

	/**
	 * Initialize the View
	 * 
	 * @since    2.1.5
	 * 
	 * @param    object    Attributes
	 * 
	 * @return   void
	 */
	initialize: function() {

		_.defaults( this.options, {
			mode: [ 'select' ],
			slug:   'media'
		});

		this._createRegions();
		this._createStates();
	},

	/**
	 * Create the frame's regions.
	 * 
	 * @since    2.1.5
	 */
	_createRegions: function() {

		// Clone the regions array.
		this.regions = this.regions ? this.regions.slice() : [];

		var slug = this.options.slug;

		// Initialize regions.
		_.each( this.regions, function( region ) {
			this[ region ] = new wp.media.controller.Region({
				view:     this,
				id:       region,
				selector: '.' + slug + '-frame-' + region
			});
		}, this );
	},

	/**
	 * Create the frame's states.
	 * 
	 * @since    2.1.5
	 */
	_createStates: function() {

		// Create the default `states` collection.
		this.states = new Backbone.Collection( null, {
			model: wp.media.controller.State
		});

		// Ensure states have a reference to the frame.
		this.states.on( 'add', function( model ) {
			model.frame = this;
			model.trigger( 'ready' );
		}, this );

		if ( this.options.states ) {
			this.states.add( this.options.states );
		}
	},

	/**
	 * Render the View.
	 * 
	 * @since    2.1.5
	 */
	render: function() {

		// Activate the default state if no active state exists.
		if ( ! this.state() && this.options.state ) {
			this.setState( this.options.state );
		}

		return wp.media.View.prototype.render.apply( this, arguments );
	}

});

// Make the `Frame` a `StateMachine`.
_.extend( wpmoly.view.Frame.prototype, wp.media.controller.StateMachine.prototype );
