"use strict";(window["webpackJsonpPluginswag-pay-pal"]=window["webpackJsonpPluginswag-pay-pal"]||[]).push([[598],{5598:function(a,n,e){e.r(n);let{Component:t}=Shopware;t.override("sw-dashboard-index",{template:'{% block sw_dashboard_index_content_campaign_banner %}\n    {% parent %}\n\n    <swag-paypal-campaign-banner v-if="showPayPalBanner"></swag-paypal-campaign-banner>\n{% endblock %}\n',inject:["systemConfigApiService"],data(){return{payPalSystemConfig:null}},computed:{showPayPalBanner(){return!!this.payPalSystemConfig&&new Date<new Date("2022-12-31")&&(this.payPalSystemConfig["SwagPayPal.settings.clientId"]||this.payPalSystemConfig["SwagPayPal.settings.clientIdSandbox"])}},methods:{createdComponent(){this.$super("createdComponent"),this.systemConfigApiService.getValues("SwagPayPal.settings").then(a=>{this.payPalSystemConfig=a})}}})}}]);