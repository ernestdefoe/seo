import app from "flarum/admin/app";
import { extend } from "flarum/common/extend";
import DashboardPage from "flarum/admin/components/DashboardPage";
import SeoWidget from "./components/SeoWidget";
import SettingsPage from "./Pages/SettingsPage";
import PermissionGrid from "flarum/admin/components/PermissionGrid";

app.initializers.add("ernestdefoe-seo", () => {
  app.extensionData.for("ernestdefoe-seo").registerPage(SettingsPage);

  // Add widget
  extend(DashboardPage.prototype, "availableWidgets", (widgets) => {
    widgets.add("seo-widget", <SeoWidget />, 500);
  });

  app.extensionData.for("ernestdefoe-seo").registerPermission(
    {
      icon: "fas fa-search",
      label: app.translator.trans(
        "ernestdefoe-seo.admin.permissions.configure_seo"
      ),
      permission: "seo.canConfigure",
    },
    "seo",
    90
  );

  // Add addPermissions
  extend(PermissionGrid.prototype, "permissionItems", function (items) {
    // Add knowledge base permissions
    items.add(
      "seo",
      {
        label: "SEO",
        children: this.attrs.extensionId
          ? app.extensionData
              .getExtensionPermissions(this.attrs.extensionId, "seo")
              .toArray()
          : app.extensionData.getAllExtensionPermissions("seo").toArray(),
      },
      80
    );
  });
});

export * from "./components";
export * from "./Pages";
