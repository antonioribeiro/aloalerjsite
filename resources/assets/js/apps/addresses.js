const appName = 'vue-addresses'
import editMixins from '../mixins/edit-mixins'


if (jQuery("#" + appName).length > 0) {
    const app = new Vue({
        el: '#'+appName,

        mixins: [editMixins],

        data: {
            tables: {
                addresses: [],
            },

            pesquisa: '',

            refreshing: false,

            filler: false,

            typeTimeout: null,

            form: {
                zipcode: null,
                street: null,
                neighbourhood: null,
                city: null,
                state: null,
            }
        },

        methods: {
            refresh() {
                let $this = this

                $this.refreshing = true

                axios.get('/api/v1/zipcode/'+this.form.zipcode)
                .then(function(response) {
                    $this.tables.addresses = response.data

                    if (response.data.addresses[0].street_name) {
                        $this.form.zipcode = response.data.addresses[0].zip
                        $this.form.street = response.data.addresses[0].street_name
                        $this.form.neighbourhood = response.data.addresses[0].neighborhood
                        $this.form.city = response.data.addresses[0].city
                        $this.form.state = response.data.addresses[0].state_id
                        $this.form.country = 'Brasil'
                        document.getElementById("number").focus();
                    }

                    $this.refreshing = false
                })
                .catch(function(error) {
                    console.log(error)

                    $this.tables.addresses = []

                    $this.refreshing = false
                })
            },

            typeKeyUp() {
                clearTimeout(this.timeout)

                let $this = this

                this.timeout = setTimeout(function () { $this.refresh() }, 500)
            },

            isNumber: function(evt) {
                evt = (evt) ? evt : window.event;
                charCode = (evt.which) ? evt.which : evt.keyCode;
                if ((charCode > 31 && (charCode < 48 || charCode > 57)) && charCode !== 46) {
                    evt.preventDefault();;
                } else {
                    return true;
                }
            }
        },

        mounted() {
            // this.refresh()            
        },
    })
}
