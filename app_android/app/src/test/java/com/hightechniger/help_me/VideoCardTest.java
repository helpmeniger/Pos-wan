package com.hightechniger.help_me;

import android.app.Activity;
import android.content.Intent;
import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.Robolectric;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.annotation.Config;
import org.robolectric.annotation.GraphicsMode;

@RunWith(RobolectricTestRunner.class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = 35, qualifiers = "w411dp-h891dp-xxhdpi")
public class VideoCardTest {
    @Test public void card() throws Exception {
        Activity a = Robolectric.buildActivity(LessonReaderActivity.class, new Intent().putExtra("lesson", "ordinateur-05")).setup().get();
        ScreensTest.shot(a, "v_lecon_video", 0);
        for (String code : new String[]{"2019", "2018", "2001", "2002", "2025", "2016", "1000", "1001", "1011", "1006", "1010", "1013", "2003", "2014", "1003", "1007", "1018", "P001", "P002", "P003"})
            org.junit.Assert.assertTrue(code, AnimLessonActivity.exists(a, code));
        com.hightechniger.help_me.ui.Course course = com.hightechniger.help_me.ui.Course.get(a);
        org.junit.Assert.assertEquals("P001", AnimLessonActivity.codeFor(a, course.lesson("powerpoint-x002")));
        org.junit.Assert.assertEquals("P003", AnimLessonActivity.codeFor(a, course.lesson("powerpoint-x006")));
        Robolectric.buildActivity(AnimLessonActivity.class, new Intent().putExtra(AnimLessonActivity.EXTRA_CODE, "2019")).setup().pause().destroy();
        Robolectric.buildActivity(SimulatorActivity.class, new Intent()).setup().pause().destroy();
    }
}
